<?php
declare(strict_types=1);

namespace App\Auth;

use App\Config\Config;
use App\Database\Db;
use App\Mail\MailService;
use App\Referral\ReferralService;
use App\Support\Clock;
use App\Support\Uuid;
use PDO;

/**
 * Core authentication & account business logic. Controllers (API + web)
 * stay thin and delegate every rule to this service.
 */
final class AuthService
{
    public function __construct(
        private readonly Config $config,
        private readonly PasswordService $passwords,
        private readonly TokenService $tokens,
        private readonly MailService $mailer,
        // M7: optional, damit bestehende Aufrufer/Tests AuthService ohne
        // Referral-Stack konstruieren können. In public/index.php verdrahtet.
        private readonly ?ReferralService $referrals = null,
    ) {}

    /**
     * Optionaler Hook für die Crew-Invariante bei Account-Löschung
     * (GAME_RUSH_BACKEND.md §12.1). Per Setter verdrahtet, weil CrewService
     * erst nach AuthService konstruiert wird; fehlt er (z. B. in reinen
     * Auth-Tests), bleibt das Verhalten unverändert.
     */
    private ?\App\Game\Crew\CrewService $crews = null;

    public function setCrewService(\App\Game\Crew\CrewService $crews): void
    {
        $this->crews = $crews;
    }

    /**
     * Server-justierbares Tages-Kontingent für Neuregistrierungen
     * (game_config.register_daily_max). Per Setter verdrahtet, weil GameConfig
     * in public/index.php erst nach AuthService konstruiert wird; fehlt er
     * (z. B. in reinen Auth-Tests), ist die Drossel wirkungslos.
     */
    private ?\App\Game\GameConfig $gameConfig = null;

    public function setGameConfig(\App\Game\GameConfig $gameConfig): void
    {
        $this->gameConfig = $gameConfig;
    }

    /**
     * Registrierung. Antwortet aus Sicht des Aufrufers immer identisch
     * (kein Tokens-Response, generischer 202-Status), damit es keine
     * Account-Enumeration über diesen Endpoint gibt.
     *
     * Verhalten:
     *  - neue E-Mail            → User anlegen + Verify-Mail senden
     *  - bestehend, unverified  → Verify-Mail erneut senden
     *  - bestehend, verified    → silent no-op (Mail nur, falls man sich
     *                             später für eine "someone tried" Mail
     *                             entscheidet — aktuell bewusst nicht,
     *                             um keinen Mail-Spam-Vektor zu öffnen)
     *  - deleted/disabled       → silent no-op
     *
     * M7: Optionaler `referralCode`. Bei gültigem Code eines aktiven Werbers
     * wird der neue User verknüpft (referred_by + referrals-Zeile). Ein
     * unbekannter Code blockiert die Registrierung NICHT (wird ignoriert).
     */
    public function register(string $email, string $password, ?string $displayName, ?string $referralCode = null): void
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();

        // Wachstums-Drossel: globales Tages-Kontingent für Neuregistrierungen
        // (game_config.register_daily_max, 0 = aus). Bewusst VOR dem E-Mail-
        // Lookup, damit an vollen Tagen für ALLE Anfragen dieselbe Antwort
        // kommt — sonst ließe sich über 202-vs-429 die Existenz einer E-Mail
        // ableiten (Account-Enumeration). Zählt alle heute (UTC) angelegten
        // Accounts; die kleine Race zweier gleichzeitiger Signups ist bei
        // einer weichen Drossel akzeptabel.
        $dailyMax = $this->gameConfig?->int('register_daily_max') ?? 0;
        if ($dailyMax > 0) {
            $dayStartUtc = substr($now, 0, 10) . ' 00:00:00';
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_at >= ?');
            $cnt->execute([$dayStartUtc]);
            if ((int)$cnt->fetchColumn() >= $dailyMax) {
                throw new AuthException(
                    'registration_limit_reached',
                    'Wir nehmen aktuell nur eine begrenzte Zahl neuer Mitglieder pro Tag auf. Bitte versuche es später noch einmal.',
                    429,
                );
            }
        }

        $stmt = $pdo->prepare('SELECT id, status, email_verified_at FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['status'] === 'active' && $existing['email_verified_at'] === null) {
                $this->createAndSendVerification(
                    (int)$existing['id'],
                    $email,
                    $displayName, // nur als Mail-Anrede, ändert nichts am gespeicherten Profil
                );
            }
            return;
        }

        $publicId = Uuid::v4();
        $hash     = $this->passwords->hash($password);

        $rawVerify = TokenService::randomToken();
        $verifyExpires = Clock::utcPlusSeconds($this->config->int('EMAIL_VERIFY_TTL', 86400));

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO users (public_id, email, password_hash, display_name, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, "active", ?, ?)'
            );
            $ins->execute([$publicId, $email, $hash, $displayName, $now, $now]);
            $userId = (int)$pdo->lastInsertId();

            $vins = $pdo->prepare(
                'INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at)
                 VALUES (?, ?, ?, ?)'
            );
            $vins->execute([$userId, TokenService::hashToken($rawVerify), $verifyExpires, $now]);

            // M7: Werber-Verknüpfung innerhalb derselben Transaktion, damit
            // User + referrals-Zeile atomar zusammen entstehen. Unbekannter
            // oder leerer Code ist ein No-Op.
            $this->referrals?->linkOnRegister($userId, $referralCode);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->sendVerifyMail($email, $displayName, $rawVerify);
    }

    /**
     * Konto-Anlage im Vereins-Aktivierungs-Flow (CrewInvite_Onboarding_Spec §10.4):
     * Die E-Mail ist per Aktivierungs-Token bereits nachgewiesen → Konto wird
     * SOFORT als verifiziert angelegt, OHNE Bestätigungsmail. Gibt die userId
     * zurück. Existiert bereits ein Konto zur E-Mail, wird dessen id geliefert
     * (der Aufrufer entscheidet dann über den Login-Pfad).
     */
    /** Handle-Format: 3–30 Zeichen a–z, 0–9, _. */
    public function handleFormatValid(string $handle): bool
    {
        return preg_match('/^[a-z0-9_]{3,30}$/', $handle) === 1;
    }

    public function handleTaken(string $handle): bool
    {
        $s = Db::pdo()->prepare('SELECT 1 FROM users WHERE public_handle = ? LIMIT 1');
        $s->execute([$handle]);
        return $s->fetchColumn() !== false;
    }

    /** Freier Handle-Vorschlag aus einem Basistext (Vereinsname). */
    public function suggestFreeHandle(string $base): string
    {
        $base = strtolower($base);
        $base = (string)preg_replace('/[^a-z0-9_]+/', '_', $base);
        $base = trim((string)preg_replace('/_+/', '_', $base), '_');
        $base = substr($base, 0, 24);
        if (strlen($base) < 3) {
            $base = 'verein';
        }
        if (!$this->handleTaken($base)) {
            return $base;
        }
        for ($i = 0; $i < 20; $i++) {
            $c = substr($base, 0, 20) . (string)random_int(10, 9999);
            if (!$this->handleTaken($c)) {
                return $c;
            }
        }
        return $base . (string)random_int(1000, 9999);
    }

    /** @return int|null userId, falls ein Konto zur E-Mail existiert. */
    public function userIdByEmail(string $email): ?int
    {
        $stmt = Db::pdo()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $r = $stmt->fetch();
        return $r ? (int)$r['id'] : null;
    }

    public function registerVerifiedForClub(string $email, string $password, ?string $displayName, ?string $preferredHandle = null): int
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($r = $stmt->fetch()) {
            return (int)$r['id'];
        }
        $publicId = Uuid::v4();
        $hash = $this->passwords->hash($password);
        // public_handle: bevorzugt den vom Vorstand gewählten (validiert), sonst
        // aus dem Vereinsnamen abgeleitet. Der Vorstand wird Crew-Captain, und die
        // Captain-Erkennung setzt einen Handle voraus.
        $base = strtolower((string)($displayName ?? ''));
        $base = (string)preg_replace('/[^a-z0-9_]+/', '_', $base);
        $base = trim((string)preg_replace('/_+/', '_', $base), '_');
        $base = substr($base, 0, 24);
        if (strlen($base) < 3) {
            $base = 'verein';
        }
        // Kandidatenreihe: gewünschter Handle (falls gültig) → Basis → Basis+Zufall.
        $candidates = [];
        $pref = $preferredHandle !== null ? strtolower(trim($preferredHandle)) : null;
        if ($pref !== null && $this->handleFormatValid($pref)) {
            $candidates[] = $pref;
        }
        $candidates[] = $base;

        $ins = $pdo->prepare(
            'INSERT INTO users (public_id, email, password_hash, display_name, public_handle, status, email_verified_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "active", ?, ?, ?)'
        );
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $handle = $candidates[$attempt] ?? (substr($base, 0, 20) . (string)random_int(10, 9999));
            try {
                $ins->execute([$publicId, $email, $hash, $displayName, $handle, $now, $now, $now]);
                return (int)$pdo->lastInsertId();
            } catch (\PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('Konnte keinen eindeutigen Handle vergeben.');
    }

    /**
     * @return array{tokens:array,user:array}
     * @throws AuthException
     */
    public function login(string $email, string $password, string $client, ?string $ua, ?string $ipBin): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, email, password_hash, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active' || !$this->passwords->verify($password, $row['password_hash'])) {
            throw new AuthException('invalid_credentials', 'Ungültige Anmeldedaten.', 401);
        }

        if ($this->passwords->needsRehash($row['password_hash'])) {
            $newHash = $this->passwords->hash($password);
            $upd = $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
            $upd->execute([$newHash, Clock::nowUtcString(), (int)$row['id']]);
        }

        $tokens = $this->tokens->issueSession((int)$row['id'], $client, $ua, $ipBin);
        return [
            'tokens' => $tokens,
            'user'   => $this->loadUserPublic((int)$row['id']),
        ];
    }

    /**
     * @return array{tokens:array,user:array}
     * @throws AuthException
     */
    public function refresh(string $refreshToken, ?string $ua, ?string $ipBin): array
    {
        $rotated = $this->tokens->rotateRefresh($refreshToken, $ua, $ipBin);
        if ($rotated === null) {
            throw new AuthException('invalid_token', 'Refresh-Token ist ungültig oder abgelaufen.', 401);
        }

        return [
            'tokens' => $rotated,
            'user'   => $this->loadUserPublic($rotated['user_id']),
        ];
    }

    public function logout(int $sessionId): void
    {
        $this->tokens->revokeSession($sessionId);
    }

    public function logoutAll(int $userId): void
    {
        $this->tokens->revokeAllForUser($userId);
    }

    public function updateProfile(int $userId, ?string $displayName): array
    {
        $pdo = Db::pdo();
        $pdo->prepare('UPDATE users SET display_name = ?, updated_at = ? WHERE id = ?')
            ->execute([$displayName, Clock::nowUtcString(), $userId]);
        return $this->loadUserPublic($userId);
    }

    /**
     * @throws AuthException
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT password_hash, email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || !$this->passwords->verify($currentPassword, $row['password_hash'])) {
            throw new AuthException('invalid_credentials', 'Aktuelles Passwort ist falsch.', 401);
        }

        $hash = $this->passwords->hash($newPassword);
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$hash, Clock::nowUtcString(), $userId]);

        // H1: Auch die aufrufende Session entwerten — wer ein Passwort
        // ändert, soll den Vorgang ggf. mit frischem Login bestätigen.
        // Schützt zusätzlich, falls Tokens vor dem Wechsel abgegriffen wurden.
        $this->tokens->revokeAllForUser($userId);
    }

    public function requestPasswordReset(string $email, ?string $ipBin): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, email, display_name, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            // M3: Timing-Side-Channel mindern — der "echte" Pfad führt einen
            // Argon2id-Hash plus DB-Insert plus Mailversand aus, was deutlich
            // länger dauert als ein simples SELECT-no-row. Wir gleichen die
            // Latenz an, indem wir hier ebenfalls einen Argon2id-Hash auf
            // einem Dummy-Wert berechnen. Das ist nicht perfekt (kein
            // DB-Insert/Mail-Roundtrip), reduziert aber das Signal um
            // Größenordnungen — und ist deutlich billiger als asynchroner
            // Mailversand für jetzt.
            $this->passwords->hash(TokenService::randomToken());
            return;
        }

        $raw = TokenService::randomToken();
        $expires = Clock::utcPlusSeconds($this->config->int('PASSWORD_RESET_TTL', 3600));
        $now = Clock::nowUtcString();

        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at, request_ip)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([(int)$row['id'], TokenService::hashToken($raw), $expires, $now, $ipBin]);

        $this->sendResetMail($row['email'], $row['display_name'], $raw);
    }

    /**
     * @throws AuthException
     */
    public function resetPassword(string $token, string $newPassword): void
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();
        $tokenHash = TokenService::hashToken($token);

        // C3: Atomar konsumieren statt SELECT-then-UPDATE — verhindert Races,
        // bei denen zwei parallele Requests denselben Token zweimal einlösen.
        $claim = $pdo->prepare(
            'UPDATE password_resets
                SET consumed_at = ?
              WHERE token_hash = ? AND expires_at > ? AND consumed_at IS NULL'
        );
        $claim->execute([$now, $tokenHash, $now]);
        if ($claim->rowCount() === 0) {
            throw new AuthException(
                'invalid_token',
                'Reset-Token ist ungültig oder abgelaufen.',
                410,
            );
        }

        $stmt = $pdo->prepare(
            'SELECT id, user_id FROM password_resets WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AuthException('invalid_token', 'Reset-Token ist ungültig oder abgelaufen.', 410);
        }

        $hash = $this->passwords->hash($newPassword);
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$hash, $now, (int)$row['user_id']]);

        // M11: Token-Hash nicht bis zum Cleanup-Cron aufbewahren — direkt
        // löschen. Reduziert das Zeitfenster, in dem aus einer evtl.
        // kompromittierten DB-Kopie verbrauchte Hashes mit Klartext-Tokens
        // korreliert werden könnten.
        $pdo->prepare('DELETE FROM password_resets WHERE id = ?')
            ->execute([(int)$row['id']]);

        $this->tokens->revokeAllForUser((int)$row['user_id']);
    }

    /**
     * @return array user
     * @throws AuthException
     */
    public function verifyEmail(string $token): array
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();
        $tokenHash = TokenService::hashToken($token);

        // C3: Atomarer Token-Consume (siehe resetPassword).
        $claim = $pdo->prepare(
            'UPDATE email_verifications
                SET consumed_at = ?
              WHERE token_hash = ? AND expires_at > ? AND consumed_at IS NULL'
        );
        $claim->execute([$now, $tokenHash, $now]);
        if ($claim->rowCount() === 0) {
            throw new AuthException(
                'invalid_token',
                'Verifizierungstoken ist ungültig oder abgelaufen.',
                410,
            );
        }

        $stmt = $pdo->prepare(
            'SELECT id, user_id FROM email_verifications WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AuthException('invalid_token', 'Verifizierungstoken ist ungültig oder abgelaufen.', 410);
        }

        $pdo->prepare('UPDATE users SET email_verified_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$now, $now, (int)$row['user_id']]);

        // M11: konsumierten Token sofort entfernen (siehe resetPassword).
        $pdo->prepare('DELETE FROM email_verifications WHERE id = ?')
            ->execute([(int)$row['id']]);

        // M7: zählende Conversion-Stufe — falls dieser User geworben wurde,
        // 'registered' → 'verified' nachziehen.
        $this->referrals?->markVerified((int)$row['user_id']);

        return $this->loadUserPublic((int)$row['user_id']);
    }

    public function resendVerification(string $email): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, email, display_name, email_verified_at, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active' || $row['email_verified_at'] !== null) {
            return;
        }
        $this->createAndSendVerification((int)$row['id'], $row['email'], $row['display_name']);
    }

    public function resendVerificationForUser(int $userId): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT email, display_name, email_verified_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || $row['email_verified_at'] !== null) {
            return;
        }
        $this->createAndSendVerification($userId, $row['email'], $row['display_name']);
    }

    /**
     * @throws AuthException
     */
    public function deleteAccount(int $userId, string $password): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT password_hash, email, display_name FROM users WHERE id = ? AND status = "active" LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !$this->passwords->verify($password, $row['password_hash'])) {
            throw new AuthException('invalid_credentials', 'Ungültiges Passwort.', 401);
        }

        // Echte Adresse merken, BEVOR sie unten im Transaktions-Update
        // anonymisiert wird — für die Löschbestätigungs-Mail.
        $origEmail = (string)($row['email'] ?? '');
        $origName  = $row['display_name'] !== null ? (string)$row['display_name'] : null;

        $now = Clock::nowUtcString();
        $scrubbedEmail = "deleted+{$userId}@invalid";

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE users SET status = "deleted", deleted_at = ?, email = ?, display_name = NULL,
                        avatar_path = NULL, referral_code = NULL, referred_by = NULL, updated_at = ?
                 WHERE id = ?'
            )->execute([$now, $scrubbedEmail, $now, $userId]);

            // M4d: Avatar-Dateien des Users physisch entfernen (Privacy).
            // Best effort — Pfad analog AvatarService. Fehler dürfen das
            // Löschen nicht blockieren.
            try {
                $avatarBase = (string)$this->config->get('STORAGE_AVATARS_DIR', '');
                if ($avatarBase === '') {
                    $avatarBase = dirname(__DIR__, 2) . '/storage/avatars';
                }
                $avatarDir = rtrim($avatarBase, '/') . '/' . $userId;
                if (is_dir($avatarDir)) {
                    foreach ((array)@scandir($avatarDir) as $entry) {
                        if ($entry === '.' || $entry === '..') { continue; }
                        $sub = $avatarDir . '/' . $entry;
                        if (is_file($sub)) { @unlink($sub); }
                    }
                    @rmdir($avatarDir);
                }
            } catch (\Throwable $e) {
                error_log('AuthService::deleteAccount: Avatar-Cleanup fehlgeschlagen: ' . $e->getMessage());
            }

            // M2 Phase 1: Auch die Routen des Users soft-löschen, damit
            // sie nicht mehr in der Bibliothek auftauchen und der
            // Cleanup-Cron sie nach dem konfigurierten Karenz-Zeitraum
            // hart entfernen kann (Files + DB-Zeilen). Wenn die
            // routes-Tabelle noch nicht existiert (z. B. weil die
            // M2-Migration noch nicht eingespielt ist), schlucken wir
            // den Fehler — der User darf nicht an einer fehlenden
            // Tabelle scheitern.
            try {
                $pdo->prepare(
                    'UPDATE routes SET deleted_at = ?, updated_at = ?
                     WHERE user_id = ? AND deleted_at IS NULL'
                )->execute([$now, $now, $userId]);
            } catch (\PDOException $e) {
                // 1146 = Base table or view not found — nur dann ignorieren.
                if (!str_contains($e->getMessage(), '1146')) {
                    throw $e;
                }
                error_log('AuthService::deleteAccount: routes-Tabelle existiert nicht, überspringe Soft-Delete der Routen.');
            }

            // M3 Phase 1: Social-Beziehungen hart entfernen. Wir behalten
            // den User-Datensatz für Audit/Referential-Integrity (status =
            // 'deleted'), aber sein Profil ist nicht mehr sichtbar — also
            // sollen auch keine Geister-Follows oder -Blocks bestehen.
            // Wenn die Tabellen noch nicht existieren (z. B. Pre-M3-DB),
            // wird das geschluckt — analog zur routes-Logik darüber.
            foreach (['follows' => 'follower_id = ? OR followee_id = ?',
                      'user_blocks' => 'blocker_id = ? OR blocked_id = ?'] as $tbl => $where) {
                try {
                    $pdo->prepare("DELETE FROM {$tbl} WHERE {$where}")
                        ->execute([$userId, $userId]);
                } catch (\PDOException $e) {
                    if (!str_contains($e->getMessage(), '1146')) {
                        throw $e;
                    }
                    error_log("AuthService::deleteAccount: {$tbl}-Tabelle existiert nicht, überspringe.");
                }
            }

            // M4a/M4b: Likes + Kommentare des Users hart entfernen. Wie
            // bei follows/blocks greift kein CASCADE, weil der User nur
            // soft-deleted wird. Single-Placeholder-WHERE, daher eigene
            // Blöcke statt des Zwei-Parameter-Loops oben.
            foreach (['route_likes', 'route_comments', 'oauth_connections', 'oauth_states'] as $tbl) {
                try {
                    $pdo->prepare("DELETE FROM {$tbl} WHERE user_id = ?")
                        ->execute([$userId]);
                } catch (\PDOException $e) {
                    if (!str_contains($e->getMessage(), '1146')) {
                        throw $e;
                    }
                    error_log("AuthService::deleteAccount: {$tbl}-Tabelle existiert nicht, überspringe.");
                }
            }

            // M4c: Notifications hart entfernen — als Empfänger UND als
            // Auslöser. Da der User nur soft-deleted wird, greift kein
            // CASCADE; sonst blieben Einträge mit actor_id = gelöschter
            // User samt public_handle in FREMDEN Inboxen sichtbar.
            try {
                $pdo->prepare('DELETE FROM notifications WHERE user_id = ? OR actor_id = ?')
                    ->execute([$userId, $userId]);
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), '1146')) {
                    throw $e;
                }
                error_log('AuthService::deleteAccount: notifications-Tabelle existiert nicht, überspringe.');
            }

            // M7: Referral-Beziehungen entfernen — als Werber UND als
            // Geworbener. Der FK ON DELETE CASCADE greift nur beim
            // Hard-Delete; da wir nur soft-deleten, putzen wir hier explizit,
            // damit kein Geworbener mehr auf einen anonymisierten Werber zeigt.
            try {
                $pdo->prepare('DELETE FROM referrals WHERE referrer_id = ? OR referred_user_id = ?')
                    ->execute([$userId, $userId]);
            } catch (\PDOException $e) {
                if (!str_contains($e->getMessage(), '1146')) {
                    throw $e;
                }
                error_log('AuthService::deleteAccount: referrals-Tabelle existiert nicht, überspringe.');
            }

            // GAME_RUSH §12.1: Crew-Invariante. Ein Captain darf NIE eine
            // nicht-leere Crew ohne Captain hinterlassen — CrewService promotet
            // das älteste Mitglied bzw. löst eine Solo-Crew sauber auf. Läuft in
            // DERSELBEN Transaktion (CrewService::transactional erkennt das).
            if ($this->crews !== null) {
                try {
                    $this->crews->handleAccountDeletion($userId);
                } catch (\PDOException $e) {
                    if (!str_contains($e->getMessage(), '1146')) {
                        throw $e;
                    }
                    error_log('AuthService::deleteAccount: Crew-Tabellen existieren nicht, überspringe.');
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->tokens->revokeAllForUser($userId);

        // Löschung bestätigen (DSGVO-Transparenz + „warst du das nicht?"-Hinweis).
        // Best effort: eine fehlgeschlagene Mail darf die bereits vollzogene
        // Löschung nicht rückwirkend als Fehler erscheinen lassen.
        if ($origEmail !== '') {
            $this->sendAccountDeletedMail($origEmail, $origName);
        }
    }

    private function sendAccountDeletedMail(string $email, ?string $displayName): void
    {
        $support = (string)$this->config->get('SUPPORT_EMAIL', 'grava@benx.de');
        $ok = $this->mailer->send($email, $displayName, 'account_deleted', [
            'display_name'  => $displayName,
            'app_name'      => 'CYBERRIDE',
            'support_email' => $support !== '' ? $support : 'grava@benx.de',
        ]);
        if (!$ok) {
            error_log("AuthService: Lösch-Bestätigungsmail an {$email} konnte nicht versendet werden.");
        }
    }

    /**
     * Web-Löschpfad (DSGVO Art. 17): Konto per E-Mail + Passwort löschen, ohne
     * eine Session anzulegen (anders als login()). Für die öffentliche
     * Lösch-Seite, damit auch App-only-Nutzer ihr Konto im Browser entfernen
     * können. Generische Fehler (kein Nutzer vs. falsches Passwort → identische
     * Meldung) verhindern Nutzer-Enumeration.
     *
     * @throws AuthException
     */
    public function deleteAccountByEmail(string $email, string $password): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND status = "active" LIMIT 1');
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new AuthException('invalid_credentials', 'Ungültige Anmeldedaten.', 401);
        }
        // deleteAccount verifiziert das Passwort und wirft bei Fehlschlag ebenfalls
        // invalid_credentials — die Meldung bleibt also identisch.
        $this->deleteAccount((int)$id, $password);
    }

    /**
     * L2: wirft, statt ein leeres Array zu liefern. Aufrufer können sich
     * darauf verlassen, dass der Rückgabewert die public-User-Form hat.
     *
     * @return array<string,mixed> public user representation
     */
    public function loadUserPublic(int $userId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT public_id, email, display_name, public_handle, email_verified_at, created_at, social_optin
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException("User #{$userId} nicht gefunden — Datenintegritätsverletzung.");
        }
        return [
            'id'             => $row['public_id'],
            'email'          => $row['email'],
            'display_name'   => $row['display_name'],
            'public_handle'  => $row['public_handle'],
            'email_verified' => $row['email_verified_at'] !== null,
            'created_at'     => Clock::toIso8601($row['created_at']),
            'social_optin'   => (bool)$row['social_optin'],
        ];
    }

    /** Setzt das Opt-in für automatische personenbezogene Social-Posts (Konzept §8). */
    public function setSocialOptIn(int $userId, bool $enabled): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('UPDATE users SET social_optin = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([$enabled ? 1 : 0, $userId]);
        return $this->loadUserPublic($userId);
    }

    /**
     * M3 Phase 0: setzt einen public_handle einmalig. One-time-Set-
     * Garantie wird hier erzwungen (DB-Spalte ist NULL bis zum
     * ersten Set; wir prüfen NULL → INSERT, sonst werfen wir 409).
     *
     * Liefert die aktualisierte Public-User-Form. Konflikt mit
     * einem anderen User wirft AuthException(409, 'handle_taken').
     */
    public function setPublicHandle(int $userId, string $handle): array
    {
        $pdo = Db::pdo();
        $existing = $pdo->prepare('SELECT public_handle FROM users WHERE id = ? LIMIT 1');
        $existing->execute([$userId]);
        $current = $existing->fetchColumn();
        if ($current !== false && $current !== null && $current !== '') {
            // One-time-Garantie: bestehender Handle bleibt. Wenn der
            // User seinen alten erneut setzt, ist das idempotent OK,
            // sonst 409.
            if ((string)$current === $handle) {
                return $this->loadUserPublic($userId);
            }
            throw new AuthException(
                'handle_locked',
                'Du hast bereits einen Handle gesetzt. Wende dich an den Support, falls du ihn ändern willst.',
                409,
            );
        }
        $stmt = $pdo->prepare('UPDATE users SET public_handle = ? WHERE id = ?');
        try {
            $stmt->execute([$handle, $userId]);
        } catch (\PDOException $e) {
            // 1062 = duplicate entry on UNIQUE constraint
            if ($e->errorInfo[1] ?? 0) {
                if ((int)($e->errorInfo[1]) === 1062) {
                    throw new AuthException(
                        'handle_taken',
                        'Dieser Handle ist bereits vergeben.',
                        409,
                    );
                }
            }
            throw $e;
        }
        return $this->loadUserPublic($userId);
    }

    private function createAndSendVerification(int $userId, string $email, ?string $displayName): void
    {
        $raw = TokenService::randomToken();
        $expires = Clock::utcPlusSeconds($this->config->int('EMAIL_VERIFY_TTL', 86400));
        Db::pdo()->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?)'
        )->execute([$userId, TokenService::hashToken($raw), $expires, Clock::nowUtcString()]);

        $this->sendVerifyMail($email, $displayName, $raw);
    }

    /**
     * Basis-URL für nutzerseitige Web-Links in E-Mails (Verify/Reset).
     * PUBLIC_WEB_URL erlaubt eine marken-gleiche Domain (z. B. cyberride.world)
     * für die Links, ohne APP_URL anzufassen (dort hängen Strava-OAuth-Callback
     * und die Admin-Host-Ableitung). Fällt auf APP_URL zurück, wenn nicht gesetzt.
     */
    private function publicWebBase(): string
    {
        $base = (string)$this->config->get('PUBLIC_WEB_URL', '');
        if ($base === '') {
            $base = (string)$this->config->get('APP_URL', '');
        }
        return rtrim($base, '/');
    }

    private function sendVerifyMail(string $email, ?string $displayName, string $rawToken): void
    {
        $url = $this->publicWebBase() . '/verify-email?token=' . urlencode($rawToken);
        $hours = max(1, (int)round($this->config->int('EMAIL_VERIFY_TTL', 86400) / 3600));
        $ok = $this->mailer->send($email, $displayName, 'verify_email', [
            'display_name' => $displayName,
            'verify_url'   => $url,
            'hours_valid'  => $hours,
            'app_name'     => 'CYBERRIDE',
        ]);
        // H7/L6: bei Mail-Fehlern den Operator informieren, aber den
        // User-Flow nicht hart brechen — der Resend-Endpoint kann es
        // wiederholen. Token wurde bereits in DB persistiert.
        if (!$ok) {
            error_log("AuthService: Verify-Mail an {$email} konnte nicht versendet werden.");
        }
    }

    private function sendResetMail(string $email, ?string $displayName, string $rawToken): void
    {
        $url = $this->publicWebBase() . '/reset-password?token=' . urlencode($rawToken);
        $minutes = max(1, (int)round($this->config->int('PASSWORD_RESET_TTL', 3600) / 60));
        $ok = $this->mailer->send($email, $displayName, 'reset_password', [
            'display_name' => $displayName,
            'reset_url'    => $url,
            'minutes_valid'=> $minutes,
            'app_name'     => 'CYBERRIDE',
        ]);
        if (!$ok) {
            error_log("AuthService: Reset-Mail an {$email} konnte nicht versendet werden.");
        }
    }
}
