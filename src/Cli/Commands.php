<?php
declare(strict_types=1);

namespace App\Cli;

use App\Auth\TokenService;
use App\Config\Config;
use App\Database\Migrator;
use App\Engagement\NotificationService;
use App\Heatmap\HeatmapService;
use App\Heatmap\HeatmapLinesService;
use App\Routes\RouteService;

final class Commands
{
    public function __construct(
        private readonly string $basePath,
        private readonly TokenService $tokens,
        private readonly RouteService $routes,
        private readonly Config $config,
        private readonly ?NotificationService $notifications = null,
        private readonly ?HeatmapService $heatmap = null,
        private readonly ?HeatmapLinesService $heatmapLines = null,
        private readonly ?\App\Game\GameRecomputeService $gameRecompute = null,
        private readonly ?\App\Game\Rush\RushService $rushService = null,
        private readonly ?\App\Game\Crew\CrewService $crewService = null,
        private readonly ?\App\Game\EdgeRecordBackfillService $edgeBackfill = null,
        private readonly ?\App\Game\GameNotificationDispatcher $gameDispatcher = null,
        private readonly ?\App\Game\GameHistoryService $gameHistory = null,
        private readonly ?\App\Game\RegionImportService $regionImport = null,
        private readonly ?\App\Game\RegionOwnershipService $regionOwnership = null,
        private readonly ?\App\Social\SocialService $social = null,
    ) {}

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        switch ($command) {
            case 'cli:migrate':
            case 'migrate':
                return $this->migrate();

            case 'cron:cleanup':
            case 'cleanup':
                return $this->cleanup();

            case 'cron:heatmap':
            case 'heatmap':
                return $this->rebuildHeatmap();

            case 'cron:heatmap-lines':
            case 'heatmap-lines':
                return $this->rebuildHeatmapLines();

            case 'heatmap:manifest':
                return $this->heatmapManifest();

            case 'heatmap:rebuild-local':
                return $this->rebuildHeatmapLinesLocal($argv);

            case 'heatmap:export-edges':
                return $this->exportHeatmapEdges($argv);

            case 'game:recompute':
                return $this->recomputeGame($argv);

            case 'game:rush-tick':
                return $this->rushTick();

            case 'game:heal-crews':
                return $this->healCrews();

            case 'game:backfill-speed':
                return $this->backfillSpeed($argv);

            case 'game:notify-dispatch':
                return $this->notifyDispatch();

            case 'cron:game-snapshot':
            case 'game:snapshot-daily':
                return $this->gameSnapshotDaily();

            case 'regions:import':
                return $this->regionsImport($argv);

            case 'regions:backfill':
                return $this->regionsBackfill($argv);

            case 'regions:relink':
                return $this->regionsRelink();

            case 'cron:region-ownership':
            case 'regions:ownership-refresh':
                return $this->regionsOwnershipRefresh();

            case 'regions:push':
                return $this->regionsPush($argv);

            case 'game:test-push':
                return $this->gameTestPush($argv);

            case 'cron:social-collect':
            case 'social:collect':
                return $this->socialCollect($argv);

            case 'cron:social-publish':
            case 'social:publish':
                return $this->socialPublish();

            case 'social:preview':
                return $this->socialPreview($argv);

            case 'internal:logtail':
            case 'logtail':
                return $this->logTail($argv);

            case 'internal:apns-check':
            case 'apns-check':
                return $this->apnsCheck();

            case 'user:verify':
                return $this->verifyUser($argv);

            case 'help':
            default:
                $this->help();
                return 0;
        }
    }

    private function migrate(): int
    {
        $migrator = new Migrator($this->basePath . '/migrations');
        $applied = $migrator->migrate();
        if (empty($applied)) {
            echo "Keine ausstehenden Migrationen.\n";
            return 0;
        }
        foreach ($applied as $name) {
            echo "Migriert: {$name}\n";
        }
        return 0;
    }

    private function cleanup(): int
    {
        // 1) Token-/Session-/Rate-Limit-Cleanup (M1).
        $tokenRes = $this->tokens->cleanup();

        // 2) M2 Phase 7: hard-delete soft-deleted routes nach Karenz.
        //    Default 30 Tage — kann via .env überstimmt werden.
        $graceDays  = $this->config->int('ROUTES_SOFT_DELETE_GRACE_DAYS', 30);
        $routesRes  = $this->routes->purgeSoftDeleted($graceDays);

        // 3) M4c: gelesene Notifications nach Karenz entfernen (Default 90 Tage).
        $notifDays  = $this->config->int('NOTIFICATIONS_READ_GRACE_DAYS', 90);
        $notifPurged = $this->notifications?->purgeOldRead($notifDays) ?? 0;

        // 4) M4e: verwaiste OAuth-States (abgebrochene Connects) entfernen.
        //    Werden bei erfolgreichem Callback single-use konsumiert;
        //    der Rest ist nach einer Stunde sicher tot.
        $statesPurged = 0;
        try {
            $stmt = \App\Database\Db::pdo()->prepare(
                'DELETE FROM oauth_states WHERE created_at <= (UTC_TIMESTAMP() - INTERVAL 1 HOUR)'
            );
            $stmt->execute();
            $statesPurged = $stmt->rowCount();
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), '1146')) {
                throw $e;
            }
        }

        $merged = [];
        foreach ($tokenRes as $k => $v) {
            $merged[$k] = $v;
        }
        foreach ($routesRes as $k => $v) {
            $merged['routes_' . $k] = $v;
        }
        // 5) M4f: Heatmap-Grid aus public Routen neu aggregieren.
        $heatmapCells = $this->heatmap?->rebuild() ?? 0;

        $merged['notifications_purged'] = $notifPurged;
        $merged['oauth_states_purged']  = $statesPurged;
        $merged['heatmap_cells']        = $heatmapCells;

        echo "Cleanup abgeschlossen:\n";
        foreach ($merged as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
        // L12: Auch ins Logfile schreiben — sonst sieht ein Operator
        // den Cron-Output nur, wenn er stdout in der crontab-Zeile
        // explizit umlenkt (`>> /var/log/...`). So bleibt zumindest
        // ein Eintrag pro Cleanup-Run im PHP-Errorlog.
        $summary = implode(', ', array_map(
            static fn($k, $v) => "{$k}={$v}",
            array_keys($merged),
            array_values($merged),
        ));
        error_log("cron:cleanup [{$summary}]");
        return 0;
    }

    /** @param list<string> $argv */
    private function recomputeGame(array $argv): int
    {
        if ($this->gameRecompute === null) {
            echo "GameRecomputeService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $bbox = trim((string)($opts['bbox'] ?? ''));
        if ($bbox !== '') {
            $parts = array_map('trim', explode(',', $bbox));
            if (count($parts) !== 4 || array_filter($parts, static fn($p) => !is_numeric($p)) !== []) {
                echo "Nutzung: game:recompute --bbox=minLon,minLat,maxLon,maxLat\n";
                return 1;
            }
            [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $parts);
            $n = $this->gameRecompute->recomputeBbox($minLon, $minLat, $maxLon, $maxLat);
            echo "Spiel-Region neu berechnet: {$n} Kanten.\n";
            return 0;
        }
        $n = $this->gameRecompute->recomputeAll();
        echo "Spiel neu berechnet: {$n} Kanten.\n";
        return 0;
    }

    /**
     * Rush-Statuszeit (§4): überführt fällige Rushes (planned→active,
     * active→completed/expired), rechnet betroffene Kanten neu und stößt
     * rush_result-Push an. Für Cron (z. B. minütlich) + /internal-Endpoint.
     */
    private function rushTick(): int
    {
        if ($this->rushService === null) {
            echo "RushService nicht verfügbar.\n";
            return 1;
        }
        $res = $this->rushService->tick();
        echo "Rush-Tick: aktiviert={$res['activated']}, abgeschlossen={$res['completed']}, verfallen={$res['expired']}.\n";
        return 0;
    }

    /**
     * Datencheck + Self-Healing (§12.1): findet nicht-leere Crews ohne gültigen
     * Captain (Altbestand) und promotet das älteste Mitglied. Idempotent.
     */
    private function healCrews(): int
    {
        if ($this->crewService === null) {
            echo "CrewService nicht verfügbar.\n";
            return 1;
        }
        $healed = $this->crewService->healCaptainlessCrews();
        if ($healed === []) {
            echo "Keine captain-losen Crews gefunden.\n";
            return 0;
        }
        foreach ($healed as $h) {
            echo "Crew '{$h['slug']}': Captain → User #{$h['promoted_user_id']}.\n";
        }
        echo 'Geheilt: ' . count($healed) . " Crew(s).\n";
        return 0;
    }

    /**
     * Gibt die letzten Zeilen des PHP-Errorlogs (storage/logs/php.log) auf
     * stdout aus — read-only Diagnose ohne SSH. Wird per /internal/logtail
     * (token-geschützt) ausgelöst, z. B. um einen frischen PDO-Stacktrace
     * (SQLSTATE) nachzuschlagen.
     *
     * @param list<string> $argv
     */
    private function logTail(array $argv): int
    {
        $lines = (int)($argv[2] ?? 200);
        $lines = max(1, min(2000, $lines));
        $file  = $this->basePath . '/storage/logs/php.log';
        if (!is_file($file)) {
            echo "Kein Logfile vorhanden: {$file}\n";
            return 0;
        }
        // Effizient: nur das Dateiende lesen (bis ~512 KB), dann die
        // letzten N Zeilen ausschneiden. So bleibt der Endpoint auch bei
        // großen Logs bezahlbar.
        $maxBytes = 512 * 1024;
        $size     = (int)filesize($file);
        $fh       = fopen($file, 'rb');
        if ($fh === false) {
            echo "Logfile nicht lesbar: {$file}\n";
            return 1;
        }
        if ($size > $maxBytes) {
            fseek($fh, -$maxBytes, SEEK_END);
            fgets($fh); // angeschnittene erste Zeile verwerfen
        }
        $content = (string)stream_get_contents($fh);
        fclose($fh);

        $all  = preg_split("/\r\n|\n|\r/", rtrim($content, "\r\n"));
        $all  = $all === false ? [] : $all;
        $tail = array_slice($all, -$lines);
        echo "--- letzte " . count($tail) . " Zeilen aus storage/logs/php.log ---\n";
        echo implode("\n", $tail) . "\n";
        return 0;
    }

    private function rebuildHeatmap(): int
    {
        if ($this->heatmap === null) {
            echo "HeatmapService nicht verfügbar.\n";
            return 1;
        }
        $cells = $this->heatmap->rebuild();
        echo "Heatmap neu aggregiert: {$cells} Zellen.\n";
        return 0;
    }

    private function rebuildHeatmapLines(): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $res = $this->heatmapLines->rebuild();
        echo "Heatmap-Linien neu gematcht:\n";
        foreach ($res as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
        return 0;
    }

    /**
     * Cutover-Hinweg (Modell A), PROD-seitig: gibt das Manifest der public
     * Routen als JSON auf stdout aus. Wird per /internal/heatmap/manifest
     * ausgelöst; der lokale `pull_prod_routes.sh` holt es per curl.
     */
    private function heatmapManifest(): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $routes = $this->heatmapLines->publicManifest();
        echo json_encode([
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'count'        => count($routes),
            'routes'       => $routes,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return 0;
    }

    /**
     * Cutover-Hinweg (Modell A), LOKAL: matcht die per SFTP geholten
     * Prod-Payloads gegen die lokale Valhalla und füllt heatmap_edges — ohne
     * dass die Prod-DB lokal vorliegen muss.
     *
     *   php public/index.php heatmap:rebuild-local \
     *       --manifest=build/heatmap_manifest.json --routes-dir=build/prod_routes
     */
    private function rebuildHeatmapLinesLocal(array $argv): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $manifestPath = (string)($opts['manifest'] ?? '');
        $routesDir    = (string)($opts['routes-dir'] ?? '');
        if ($manifestPath === '' || $routesDir === '') {
            echo "Nutzung: heatmap:rebuild-local --manifest=<datei.json> --routes-dir=<verzeichnis>\n";
            return 1;
        }
        if (!is_file($manifestPath)) {
            echo "Manifest nicht gefunden: {$manifestPath}\n";
            return 1;
        }
        $raw = (string)@file_get_contents($manifestPath);
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            echo "Manifest ist kein gültiges JSON: {$e->getMessage()}\n";
            return 1;
        }
        $entries = is_array($data['routes'] ?? null) ? $data['routes'] : [];
        if ($entries === []) {
            echo "Manifest enthält keine Routen.\n";
            return 1;
        }

        $res = $this->heatmapLines->rebuildFromManifest($entries, $routesDir);
        echo "Heatmap-Linien lokal aus Manifest gematcht:\n";
        foreach ($res as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
        return 0;
    }

    /**
     * Cutover-Rückweg, LOKAL: schreibt die lokal berechneten heatmap_edges als
     * JSON nach --out. Diese Datei wird per scripts/push_heatmap_edges.sh an
     * /internal/heatmap/import auf PROD gepostet.
     *
     *   php public/index.php heatmap:export-edges --out=build/heatmap_edges.json
     */
    private function exportHeatmapEdges(array $argv): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $out  = (string)($opts['out'] ?? '');
        if ($out === '') {
            echo "Nutzung: heatmap:export-edges --out=<datei.json>\n";
            return 1;
        }
        $rows = $this->heatmapLines->exportRows();
        $json = json_encode([
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'count'        => count($rows),
            'rows'         => $rows,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $dir = dirname($out);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (@file_put_contents($out, $json) === false) {
            echo "Konnte nicht schreiben: {$out}\n";
            return 1;
        }
        echo "Export: " . count($rows) . " Kanten -> {$out}\n";
        return 0;
    }

    /**
     * APNs-Diagnose ohne SSH: prüft, ob der Server den .p8-Key tatsächlich
     * lesen und daraus ein Provider-JWT erzeugen kann. Gibt NIEMALS den Key
     * oder das JWT aus — nur Status-Flags. Pfad-Auflösung identisch zu
     * public/index.php (absolut oder relativ zum Projekt).
     */
    private function apnsCheck(): int
    {
        $enabled  = $this->config->bool('APNS_ENABLED', false);
        $keyId    = (string)($this->config->get('APNS_KEY_ID', '') ?? '');
        $teamId   = (string)($this->config->get('APNS_TEAM_ID', '98JR57G9M7') ?? '');
        $bundleId = (string)($this->config->get('APNS_BUNDLE_ID', 'world.grava.app') ?? '');
        $keyPath  = (string)($this->config->get('APNS_KEY_PATH', '') ?? '');

        $resolved = $keyPath === ''
            ? ''
            : (str_starts_with($keyPath, '/') ? $keyPath : $this->basePath . '/' . $keyPath);

        $exists   = $resolved !== '' && @is_file($resolved);
        $readable = $exists && @is_readable($resolved);
        $size     = $readable ? (int)@filesize($resolved) : 0;
        $pem      = $readable ? (string)(@file_get_contents($resolved) ?: '') : '';
        $looksPem = $pem !== '' && str_contains($pem, 'BEGIN PRIVATE KEY');

        $jwtOk = false;
        $jwtErr = null;
        if ($looksPem && $keyId !== '' && $teamId !== '') {
            try {
                \App\Push\ApnsJwt::provider($pem, $keyId, $teamId, time());
                $jwtOk = true;
            } catch (\Throwable $e) {
                $jwtErr = $e->getMessage();
            }
        }

        $usable = $enabled && $keyId !== '' && $teamId !== '' && $bundleId !== '' && $pem !== '';

        echo json_encode([
            'apns_enabled'   => $enabled,
            'key_id_set'     => $keyId !== '',
            'team_id'        => $teamId,
            'bundle_id'      => $bundleId,
            'key_path'       => $keyPath,
            'resolved_path'  => $resolved,
            'file_exists'    => $exists,
            'is_readable'    => $readable,
            'file_size'      => $size,
            'looks_like_p8'  => $looksPem,
            'jwt_mint_ok'    => $jwtOk,
            'jwt_error'      => $jwtErr,
            'config_usable'  => $usable,
            'verdict'        => $usable && $jwtOk
                ? 'OK — Push ist versandbereit.'
                : 'NICHT versandbereit — siehe Flags.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo "\n";
        return $usable && $jwtOk ? 0 : 1;
    }

    /**
     * Parst `--key=value`-Optionen aus argv (ab Index 2, hinter dem Befehl).
     *
     * @param list<string> $argv
     * @return array<string,string>
     */
    private function parseOptions(array $argv): array
    {
        $opts = [];
        foreach (array_slice($argv, 2) as $arg) {
            if (preg_match('/^--([a-z0-9\-]+)=(.*)$/i', (string)$arg, $m)) {
                $opts[$m[1]] = $m[2];
            }
        }
        return $opts;
    }

    /**
     * Spiel-Push-Zustellung (GAME_PUSH_BACKEND.md): verarbeitet den
     * Ereignis-Strom (game_event) zu Inbox-Mitteilungen + APNs, gebündelt über
     * das Digest-Zeitfenster. Für Cron (z. B. minütlich) + /internal-Endpoint.
     */
    private function notifyDispatch(): int
    {
        if ($this->gameDispatcher === null) {
            echo "GameNotificationDispatcher nicht verfügbar.\n";
            return 1;
        }
        $sent = $this->gameDispatcher->dispatch(\App\Support\Clock::nowUtc());
        echo "Spiel-Push-Dispatch: {$sent} Mitteilung(en) erzeugt.\n";
        return 0;
    }

    /**
     * Einmaliger Feldtest (GAME_PUSH_BACKEND.md): erzeugt für einen Empfänger
     * eine echte edge_taken-Mitteilung (Inbox-Eintrag + APNs mit Deep-Link
     * edge_id) — derselbe Zustell-Pfad wie der Dispatcher (notifyGame), ohne
     * auf das Digest-Fenster zu warten. Push hängt am game_takeover-Schalter
     * und an einem registrierten Gerät.
     *
     *   game:test-push --handle=<empfänger> [--actor=<auslöser-handle>] [--edge=<id>]
     *   (alternativ --user=<id> / --actor-id=<id>)
     *
     * @param list<string> $argv
     */
    private function gameTestPush(array $argv): int
    {
        if ($this->notifications === null) {
            echo "NotificationService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $pdo  = \App\Database\Db::pdo();

        $recipientId = $this->resolveUserId($pdo, (string)($opts['handle'] ?? ''), (int)($opts['user'] ?? 0));
        if ($recipientId === 0) {
            echo "Empfänger nicht gefunden. Nutzung: game:test-push --handle=<@handle> | --user=<id> [--actor=<handle>] [--edge=<id>]\n";
            return 1;
        }
        $actorId = $this->resolveUserId($pdo, (string)($opts['actor'] ?? ''), (int)($opts['actor-id'] ?? 0));
        if ($actorId === $recipientId) {
            $actorId = 0; // Self-Notification vermeiden → Digest-/aktorlose Form
        }

        $edgeId = (int)($opts['edge'] ?? 0);
        if ($edgeId <= 0) {
            // Bevorzugt eine vom Empfänger gehaltene Kante → Deep-Link landet im eigenen Revier.
            $stmt = $pdo->prepare(
                'SELECT e.id FROM game_edge e
                   JOIN game_claimant c ON c.id = e.owner_claimant_id
                  WHERE c.user_id = ? ORDER BY e.id LIMIT 1'
            );
            $stmt->execute([$recipientId]);
            $edgeId = (int)($stmt->fetchColumn() ?: 0);
            if ($edgeId <= 0) {
                $edgeId = (int)($pdo->query('SELECT id FROM game_edge ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
            }
        }
        if ($edgeId <= 0) {
            echo "Keine Kante gefunden — bitte --edge=<id> angeben.\n";
            return 1;
        }

        $this->notifications->notifyGame($recipientId, $actorId > 0 ? $actorId : null, 'edge_taken', $edgeId, 1);

        echo json_encode([
            'ok'                => true,
            'type'              => 'edge_taken',
            'recipient_user_id' => $recipientId,
            'actor_user_id'     => $actorId > 0 ? $actorId : null,
            'edge_id'           => $edgeId,
            'note'              => 'Inbox-Eintrag erstellt; APNs versandt, falls ein Gerät registriert und die game_takeover-Pref aktiv ist.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return 0;
    }

    /** Löst eine User-ID aus expliziter ID oder public_handle (mit/ohne @) auf; 0 = nicht gefunden. */
    /**
     * user:verify --email=<email>
     *
     * Markiert ein Konto als E-Mail-verifiziert (und aktiv) — für Betriebs-/
     * Test-Konten (z. B. Apple-Review-Demo), die keinen Zugriff auf das Postfach
     * haben. Nur über den token-geschützten /internal-Endpoint erreichbar.
     */
    private function verifyUser(array $argv): int
    {
        $opts  = $this->parseOptions($argv);
        $email = trim((string)($opts['email'] ?? ''));
        if ($email === '') {
            echo "Nutzung: user:verify --email=<email>\n";
            return 1;
        }
        $pdo = \App\Database\Db::pdo();
        $upd = $pdo->prepare(
            "UPDATE users
                SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()),
                    status = 'active',
                    updated_at = UTC_TIMESTAMP()
              WHERE email = ?"
        );
        $upd->execute([$email]);

        $sel = $pdo->prepare('SELECT id, email, email_verified_at, status FROM users WHERE email = ? LIMIT 1');
        $sel->execute([$email]);
        $user = $sel->fetch(\PDO::FETCH_ASSOC) ?: null;

        echo json_encode([
            'ok'    => $user !== null,
            'email' => $email,
            'user'  => $user,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return $user !== null ? 0 : 1;
    }

    private function resolveUserId(\PDO $pdo, string $handle, int $id): int
    {
        if ($id > 0) {
            return $id;
        }
        $handle = ltrim(trim($handle), '@');
        if ($handle === '') {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE public_handle = ? LIMIT 1');
        $stmt->execute([$handle]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /** @param list<string> $argv */
    /**
     * Täglicher Revier-Verlauf-Snapshot (GameHistory_Backend_Spec.md): schreibt je
     * aktivem Claimant den heutigen Stand (idempotent) und backfillt beim ersten Lauf
     * die Vergangenheit aus game_edge.owner_since/discovered_at. Für Cron gedacht
     * (z. B. täglich ~00:05 UTC).
     */
    private function gameSnapshotDaily(): int
    {
        if ($this->gameHistory === null) {
            fwrite(STDERR, "game:snapshot-daily nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $res = $this->gameHistory->snapshotAll();
        echo sprintf(
            "Revier-Verlauf: %d Claimant(s) für %s, %d neu backfillt.\n",
            $res['claimants'], $res['date'], $res['backfilled'],
        );
        return 0;
    }

    /**
     * regions:import --file=storage/regions/boundaries.geojsonseq [--levels=2,4,6,8]
     * Lädt die OSM-Verwaltungsgrenzen in die game_region-Hierarchie (Phase A der
     * Gebiets-Eroberung, CityConquest_Backend_Spec.md).
     */
    private function regionsImport(array $argv): int
    {
        if ($this->regionImport === null) {
            fwrite(STDERR, "regions:import nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        // Einmaliger Ops-Import europaweiter Grenzen: großzügiges Limit (der
        // Geometrie-Cache der Zwischen-Ebenen wächst über den ganzen Kontinent).
        ini_set('memory_limit', '3G');
        $opts = $this->parseOptions($argv);
        $file = (string)($opts['file'] ?? ($this->basePath . '/storage/regions/boundaries.geojsonseq'));
        $levels = array_values(array_filter(array_map(
            static fn($s): int => (int)trim($s),
            explode(',', (string)($opts['levels'] ?? '2,4,6,8'))
        ), static fn(int $l): bool => $l > 0));
        if ($levels === []) {
            $levels = [2, 4, 6, 8];
        }
        // --append: weiteren Kontinent (z. B. USA) hinzufügen, ohne EU zu löschen.
        // Bloßes Flag (--append) ODER --append=1; parseOptions erfasst nur letzteres,
        // daher zusätzlich argv direkt prüfen.
        $replace = !isset($opts['append']) && !in_array('--append', $argv, true);
        $log = static fn(string $m): int => fwrite(STDERR, $m . "\n");
        try {
            $res = $this->regionImport->importFromGeojsonSeq($file, $levels, $log, $replace);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Fehler: {$e->getMessage()}\n");
            return 1;
        }
        $total = array_sum($res['inserted']);
        echo sprintf("Gebiete importiert: %d (verknüpft: %d)\n", $total, $res['linked']);
        foreach ($res['inserted'] as $lvl => $cnt) {
            echo sprintf("  Ebene %d: %d\n", $lvl, $cnt);
        }
        return 0;
    }

    /**
     * regions:backfill [--all] [--batch=1000]
     * Ordnet Kanten ihr feinstes Gebiet zu (game_edge.region_id). Standard: nur
     * bisher nicht zugeordnete Kanten; --all rechnet alle neu.
     */
    private function regionsBackfill(array $argv): int
    {
        if ($this->regionImport === null) {
            echo "regions:backfill nicht verfügbar (Service nicht verdrahtet).\n";
            return 1;
        }
        ini_set('memory_limit', '2G');
        $opts = $this->parseOptions($argv);
        $onlyUnassigned = !isset($opts['all']);
        $batch = max(1, (int)($opts['batch'] ?? 500));
        // --limit begrenzt die je Aufruf verarbeiteten Kanten (fortsetzbar über
        // --after-id) — nötig auf PROD, wo ein Request Zeit-/Speicherlimits hat.
        $maxCount = isset($opts['limit']) ? max(1, (int)$opts['limit']) : null;
        $afterId = max(0, (int)($opts['after-id'] ?? 0));
        // echo (kein STDERR): läuft auch über die Internal-HTTP-Route (Web-SAPI,
        // wo die STDERR-Konstante fehlt); der Runner erfasst die Ausgabe per ob_start.
        $log = static function (string $m): void { echo $m . "\n"; };
        try {
            $res = $this->regionImport->backfillEdges($onlyUnassigned, $batch, $log, $maxCount, $afterId);
        } catch (\Throwable $e) {
            echo "Fehler: {$e->getMessage()}\n";
            return 1;
        }
        echo sprintf(
            "Backfill: %d geprüft, %d zugeordnet, last_id=%d, done=%s\n",
            $res['scanned'], $res['assigned'], $res['last_id'], $res['done'] ? '1' : '0'
        );
        return 0;
    }

    /**
     * regions:relink — verknüpft zu hoch/fehlerhaft verkettete Gebiete (v. a.
     * Inseln, deren Comune direkt am Land statt an der Provinz hing) neu, ohne
     * Neu-Import der Geometrie. Danach regions:ownership-refresh aufrufen.
     */
    private function regionsRelink(): int
    {
        if ($this->regionImport === null) {
            echo "regions:relink nicht verfügbar (Service nicht verdrahtet).\n";
            return 1;
        }
        ini_set('memory_limit', '2G');
        $log = static function (string $m): void { echo $m . "\n"; };
        $res = $this->regionImport->relinkOrphans($log);
        echo sprintf("Relink: %d geprüft, %d neu verknüpft.\n", $res['checked'], $res['relinked']);
        return 0;
    }

    /**
     * regions:ownership-refresh — rechnet den Gebiets-Besitz (game_region_ownership)
     * voll neu (Bottom-up-Rollup + Kontrollschwelle). Idempotent; Besitzwechsel
     * werden gezählt (später für region_taken/region_lost-Events nutzbar).
     */
    private function regionsOwnershipRefresh(): int
    {
        if ($this->regionOwnership === null) {
            echo "regions:ownership-refresh nicht verfügbar (Service nicht verdrahtet).\n";
            return 1;
        }
        ini_set('memory_limit', '1G');
        $res = $this->regionOwnership->recomputeAll();
        echo sprintf(
            "Gebiets-Besitz aktualisiert: %d Gebiet(e), %d Besitzwechsel.\n",
            $res['regions'], count($res['changes'])
        );
        return 0;
    }

    /**
     * regions:push [--base-url=] [--token=] [--chunk=2000]
     * Schiebt die lokal berechnete game_region-Hierarchie chunk-weise an
     * POST {base}/internal/regions/import auf PROD (verbatim inkl. id/parent_id/
     * path). Kein mysql-Client/osmium auf dem Server nötig — wie der Heatmap-
     * Cutover. Danach auf PROD /internal/regions/backfill?all=1 und
     * /internal/cron/region-ownership aufrufen.
     */
    private function regionsPush(array $argv): int
    {
        if ($this->regionImport === null) {
            fwrite(STDERR, "regions:push nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        ini_set('memory_limit', '1G');
        $opts = $this->parseOptions($argv);
        $base = rtrim((string)($opts['base-url'] ?? $this->config->get('APP_URL', '')), '/');
        $token = (string)($opts['token'] ?? $this->config->get('INTERNAL_TOKEN', ''));
        // Chunking nach BYTES (nicht Zeilen): Einzelgeometrien reichen von 1 KB
        // (Gemeinde) bis >1 MB (Bundesland mit vielen Inseln) — eine feste Zeilenzahl
        // sprengt sonst das Prod-Body-Limit (post_max_size). Default konservativ.
        $maxBytes = max(200_000, (int)($opts['max-bytes'] ?? 2_000_000));
        $maxRows = max(1, (int)($opts['chunk'] ?? 1000));
        if ($base === '' || $token === '') {
            fwrite(STDERR, "base-url und token nötig (--base-url=, --token= oder APP_URL/INTERNAL_TOKEN in .env).\n");
            return 1;
        }
        $total = $this->regionImport->regionCount();
        if ($total === 0) {
            fwrite(STDERR, "Keine Gebiete lokal — erst regions:import laufen lassen.\n");
            return 1;
        }
        $url = $base . '/internal/regions/import';
        echo sprintf("Push %d Gebiete → %s (max %.1f MB/Chunk)\n", $total, $url, $maxBytes / 1e6);

        $after = 0;
        $sent = 0;
        $first = true;
        $buffer = [];
        $bufBytes = 0;

        $flush = function () use (&$buffer, &$bufBytes, &$first, &$sent, $url, $token, $total): bool {
            if ($buffer === []) {
                return true;
            }
            $payload = json_encode(['replace' => $first, 'rows' => $buffer], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!$this->postJson($url, $token, (string)$payload)) {
                return false;
            }
            $sent += count($buffer);
            $first = false;
            echo sprintf("  … %d/%d gesendet\n", $sent, $total);
            $buffer = [];
            $bufBytes = 0;
            return true;
        };

        while (true) {
            $rows = $this->regionImport->exportPage($after, 500);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $after = (int)$r['id'];
                $rowBytes = strlen((string)json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                if ($buffer !== [] && ($bufBytes + $rowBytes > $maxBytes || count($buffer) >= $maxRows)) {
                    if (!$flush()) {
                        fwrite(STDERR, "Abbruch bei id>{$after} (HTTP-Fehler).\n");
                        return 1;
                    }
                }
                $buffer[] = $r;
                $bufBytes += $rowBytes;
            }
        }
        if (!$flush()) {
            fwrite(STDERR, "Abbruch beim letzten Chunk (HTTP-Fehler).\n");
            return 1;
        }
        echo sprintf("Fertig: %d Gebiete gepusht. Jetzt auf PROD: /internal/regions/backfill?all=1 dann /internal/cron/region-ownership\n", $sent);
        return 0;
    }

    private function postJson(string $url, string $token, string $body): bool
    {
        // Bis zu 3 Versuche gegen transiente 5xx/Netzfehler (Prod ist Shared-Hosting).
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if ($this->postJsonOnce($url, $token, $body)) {
                return true;
            }
            if ($attempt < 3) {
                echo "  … Retry {$attempt}\n";
                sleep($attempt);
            }
        }
        return false;
    }

    private function postJsonOnce(string $url, string $token, string $body): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Internal-Token: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            fwrite(STDERR, "  HTTP {$code} {$err}: " . substr((string)$resp, 0, 300) . "\n");
            return false;
        }
        return true;
    }

    private function backfillSpeed(array $argv): int
    {
        if ($this->edgeBackfill === null) {
            fwrite(STDERR, "game:backfill-speed nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $limit = max(1, (int)($opts['limit'] ?? 100));
        $sleepMs = max(0, (int)($opts['sleep-ms'] ?? 500));
        $after = max(0, (int)($opts['after-route-id'] ?? 0));
        $res = $this->edgeBackfill->run($limit, $sleepMs, $after);
        echo sprintf(
            "Backfill: %d Route(n) verarbeitet, %d Fehler, letzte route_id=%d\n",
            $res['processed'],
            $res['errors'],
            $res['last_route_id'],
        );
        return $res['errors'] > 0 ? 1 : 0;
    }

    /**
     * social:collect [--date=YYYY-MM-DD] — baut den Tagesbericht aus der
     * Aktivität und legt ihn (falls nicht leer) als pending-Kandidat in die
     * Redaktions-Queue (Twitter_Automation_Concept.md §5.1, E1). Idempotent.
     * Für Cron gedacht (z. B. täglich ~19:55 UTC, vor dem Sende-Slot).
     *
     * @param list<string> $argv
     */
    private function socialCollect(array $argv): int
    {
        if ($this->social === null) {
            fwrite(STDERR, "social:collect nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $date = trim((string)($opts['date'] ?? '')) ?: $this->social->today();
        $res  = $this->social->collectDaily($date);
        $kinds = [];
        foreach (($res['by_kind'] ?? []) as $k => $n) {
            $kinds[] = "{$k}={$n}";
        }
        echo sprintf(
            "Social-Collect %s: %d Kandidat(en), neu=%d, schon vorhanden=%d%s\n",
            $res['date'], $res['candidates'], $res['enqueued'], $res['already'],
            $kinds === [] ? '' : ' [' . implode(', ', $kinds) . ']',
        );
        return 0;
    }

    /**
     * social:publish — sendet fällige pending-Kandidaten des Kanals unter dem
     * Tages-Limit (E8). Solange SOCIAL_ENABLED=0 oder SOCIAL_DRY_RUN=1, wird
     * nichts gesendet (Dry-Run), nur protokolliert. Für Cron (z. B. 20:00).
     */
    private function socialPublish(): int
    {
        if ($this->social === null) {
            fwrite(STDERR, "social:publish nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $res = $this->social->publishPending();
        echo sprintf(
            "Social-Publish [%s%s]: gesendet=%d, übersprungen=%d, fehlgeschlagen=%d, Rest-Kontingent=%d\n",
            $res['channel'], $res['dry_run'] ? ' DRY-RUN' : '',
            $res['published'], $res['skipped'], $res['failed'], $res['remaining_quota'],
        );
        return $res['failed'] > 0 ? 1 : 0;
    }

    /**
     * social:preview [--date=YYYY-MM-DD] [--lang=en|de] — Trocken-Vorschau des
     * Tagesbericht-Textes samt Rohdaten. Speichert nichts, sendet nichts.
     *
     * @param list<string> $argv
     */
    private function socialPreview(array $argv): int
    {
        if ($this->social === null) {
            fwrite(STDERR, "social:preview nicht verfügbar (Service nicht verdrahtet).\n");
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $date = trim((string)($opts['date'] ?? '')) ?: $this->social->today();
        $lang = trim((string)($opts['lang'] ?? ''));
        $res  = $this->social->preview($date, $lang !== '' ? $lang : null);
        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return 0;
    }

    private function help(): void
    {
        echo "GRAVA Backend CLI\n";
        echo "Nutzung: php public/index.php <befehl>\n\n";
        echo "Befehle:\n";
        echo "  cli:migrate         Wendet ausstehende Migrationen an\n";
        echo "  cron:cleanup        Löscht abgelaufene Tokens, Sessions, Verifizierungen, Rate-Limits + Heatmap-Rebuild\n";
        echo "  cron:heatmap        Aggregiert die Crowd-Heatmap (Centroids) aus public Routen neu\n";
        echo "  cron:heatmap-lines  Map-Matching der public Routen -> heatmap_edges (Streckenlinien)\n";
        echo "  heatmap:manifest    (PROD) Gibt das Manifest der public Routen als JSON aus (Cutover-Hinweg)\n";
        echo "  heatmap:rebuild-local  (LOKAL) Rebuild aus Manifest + Dateien: --manifest=.. --routes-dir=..\n";
        echo "  heatmap:export-edges   (LOKAL) heatmap_edges als JSON exportieren: --out=..\n";
        echo "  game:recompute      Berechnet alle Spiel-Kanten aus den Pässen neu [--bbox=minLon,minLat,maxLon,maxLat]\n";
        echo "  game:rush-tick      Aktualisiert fällige Rush-Status (planned→active→completed/expired)\n";
        echo "  game:heal-crews     Heilt captain-lose Crews (promotet ältestes Mitglied)\n";
        echo "  game:backfill-speed Rekord-Daten auf Bestands-Pässe [--limit=100] [--sleep-ms=500] [--after-route-id=0]\n";
        echo "  game:notify-dispatch Stellt den Spiel-Ereignis-Strom als Inbox+APNs zu (Digest-Fenster)\n";
        echo "  game:test-push      (Feldtest) edge_taken-Mitteilung erzeugen: --handle=<@h>|--user=<id> [--actor=<@h>] [--edge=<id>]\n";
        echo "  social:collect      Tagesbericht bauen + in die Post-Queue legen [--date=YYYY-MM-DD]\n";
        echo "  social:publish      Fällige Post-Kandidaten senden (Dry-Run, solange SOCIAL_ENABLED=0/SOCIAL_DRY_RUN=1)\n";
        echo "  social:preview      Trocken-Vorschau des Tagesbericht-Textes [--date=YYYY-MM-DD] [--lang=en|de]\n";
        echo "  help                Zeigt diese Hilfe\n";
    }
}
