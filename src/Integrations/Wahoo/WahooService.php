<?php
declare(strict_types=1);

namespace App\Integrations\Wahoo;

use App\Database\Db;
use App\Routes\RouteRepository;
use App\Routes\RouteService;
use App\Routes\SensorMetrics;
use App\Support\Clock;
use App\Support\Crypto;

/**
 * Orchestriert den Wahoo-OAuth-Flow (Phase B). Import/FIT-Ingestion folgen in
 * Phase C/D. Analog {@see \App\Integrations\Strava\StravaService}, aber
 * **Import-only** (kein Share/Upload).
 *
 * Tokens werden über {@see Crypto} AES-256-GCM-verschlüsselt in
 * `oauth_connections` (provider='wahoo') persistiert. Der HTTP-Verkehr läuft
 * über den injizierten {@see WahooClient} (Real oder Fake — Dev-Seam).
 *
 * Scopes: user_read (Identität), workouts_read (Fahrten lesen), offline_data
 * (Pflicht für Webhooks + FIT-Download).
 */
final class WahooService
{
    /** OAuth-Scopes (space-separated, wie Wahoo sie erwartet). */
    private const SCOPES = 'user_read workouts_read offline_data';

    public function __construct(
        private readonly WahooClient $client,
        private readonly Crypto $crypto,
        private readonly string $clientId,
        private readonly string $redirectUri,
        private readonly bool $fakeMode,
        private readonly string $appUrl,
        // Ingestion-Abhängigkeiten (Phase C+). Nullable, damit reine OAuth-Nutzung
        // (Phase B) den Service ohne diese konstruieren kann.
        private readonly ?RouteService $routes = null,
        private readonly ?RouteRepository $routeRepo = null,
        private readonly ?FitDecoder $fit = null,
    ) {}

    public function isConfigured(): bool
    {
        return $this->fakeMode || ($this->clientId !== '');
    }

    /**
     * Erzeugt einen single-use State und liefert die Authorize-URL. Im Fake-Modus
     * zeigt sie direkt auf den eigenen Callback (Dummy-Code), damit der Flow ohne
     * Wahoo testbar ist.
     *
     * @param 'web'|'mobile' $flow
     */
    public function authorizeUrl(int $userId, string $flow = 'web', ?string $returnTo = null): string
    {
        $state = bin2hex(random_bytes(32));
        Db::pdo()->prepare(
            'INSERT INTO oauth_states (state, user_id, provider, flow, return_to, created_at)
             VALUES (?, ?, "wahoo", ?, ?, ?)'
        )->execute([$state, $userId, $flow, $returnTo, Clock::nowUtcString()]);

        if ($this->fakeMode) {
            return rtrim($this->appUrl, '/') . '/auth/wahoo/callback'
                . '?state=' . $state . '&code=fake-auth-code';
        }

        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'state'         => $state,
        ]);
        return 'https://api.wahooligan.com/oauth/authorize?' . $params;
    }

    /**
     * Verifiziert + konsumiert den State, tauscht den Code gegen Tokens und legt/
     * aktualisiert die Verbindung (verschlüsselt).
     *
     * @return array{user_id:int, flow:string, return_to:?string}
     */
    public function handleCallback(string $state, string $code, ?int $expectedUserId = null, ?string $grantedScope = null): array
    {
        if ($state === '' || $code === '') {
            throw new WahooException('oauth_invalid', 'Ungültige OAuth-Antwort.', 400);
        }
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT user_id, flow, return_to FROM oauth_states WHERE state = ? AND provider = "wahoo" LIMIT 1');
        $stmt->execute([$state]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new WahooException('oauth_state_invalid', 'OAuth-State unbekannt oder abgelaufen.', 400);
        }
        $userId   = (int)$row['user_id'];
        $flow     = (string)($row['flow'] ?? 'web');
        $returnTo = $row['return_to'] !== null ? (string)$row['return_to'] : null;
        $pdo->prepare('DELETE FROM oauth_states WHERE state = ?')->execute([$state]);

        // CSRF-Schutz flow-abhängig (wie Strava): web braucht Session-Bindung,
        // mobile bindet über den single-use State aus dem Bearer-Aufruf.
        if ($flow === 'web' && $expectedUserId === null) {
            throw new WahooException('oauth_state_invalid', 'OAuth-Callback ohne aktive Sitzung.', 400);
        }
        if ($expectedUserId !== null && $expectedUserId !== $userId) {
            throw new WahooException('oauth_state_invalid', 'OAuth-State gehört zu einer anderen Sitzung.', 400);
        }

        $tokens = $this->client->exchangeCode($code);
        if ($grantedScope !== null && $grantedScope !== '') {
            $tokens['scope'] = $grantedScope;
        }
        $this->persistConnection($userId, $tokens);
        return ['user_id' => $userId, 'flow' => $flow, 'return_to' => $returnTo];
    }

    public function disconnect(int $userId): void
    {
        // Best-effort-Deauthorize bei Wahoo, dann lokal löschen.
        $conn = $this->connectionRow($userId);
        if ($conn !== null) {
            try {
                $this->client->deauthorize($this->freshAccessToken($userId, $conn));
            } catch (\Throwable) {
                // Trennen darf lokal nicht an einem API-Fehler scheitern.
            }
        }
        Db::pdo()->prepare('DELETE FROM oauth_connections WHERE user_id = ? AND provider = "wahoo"')
            ->execute([$userId]);
        Db::pdo()->prepare('DELETE FROM oauth_states WHERE user_id = ? AND provider = "wahoo"')
            ->execute([$userId]);
    }

    /**
     * @return array{connected:bool, wahoo_user_id:?string, scope:?string,
     *               connected_at:?string, configured:bool, fake_mode:bool}
     */
    public function status(int $userId): array
    {
        $base = [
            'configured' => $this->isConfigured(),
            'fake_mode'  => $this->fakeMode,
        ];
        $conn = $this->connectionRow($userId);
        if ($conn === null) {
            return ['connected' => false, 'wahoo_user_id' => null, 'scope' => null, 'connected_at' => null] + $base;
        }
        return [
            'connected'     => true,
            'wahoo_user_id' => (string)$conn['provider_user_id'],
            'scope'         => $conn['scope'] === null ? null : (string)$conn['scope'],
            'connected_at'  => str_replace(' ', 'T', (string)$conn['created_at']) . 'Z',
        ] + $base;
    }

    /**
     * Importiert ein einzelnes Wahoo-Workout als private Route (Phase-C-Kern;
     * wird von Pull (Phase D) und Webhook (Phase E) genutzt). Idempotent über die
     * deterministische client_route_uuid. FIT ohne verwertbaren GPS-Track wird
     * übersprungen (z. B. Indoor). Startdatum kommt aus der FIT → korrekte
     * Datierung im Revier.
     *
     * @return array{status:string, reason?:string}
     */
    public function ingestWorkout(int $userId, string $workoutId): array
    {
        if ($this->routes === null || $this->routeRepo === null) {
            throw new WahooException('not_configured', 'Wahoo-Import ist nicht verdrahtet.', 500);
        }
        $conn = $this->connectionRow($userId);
        if ($conn === null) {
            throw new WahooException('not_connected', 'Keine Wahoo-Verbindung.', 409);
        }

        // Schon importiert? → idempotenter Skip (Pull + Webhook kollidieren nicht).
        $clientUuid = self::workoutUuid($workoutId);
        if ($this->routeExists($userId, $clientUuid)) {
            return ['status' => 'skipped', 'reason' => 'already_imported'];
        }

        $token   = $this->freshAccessToken($userId, $conn);
        $summary = $this->client->getWorkoutSummary($token, $workoutId);
        $fitUrl  = $summary['fit_file_url'] ?? null;
        if ($fitUrl === null || $fitUrl === '') {
            return ['status' => 'skipped', 'reason' => 'no_fit'];
        }

        $bytes   = $this->client->downloadFit($token, $fitUrl);
        $decoded = ($this->fit ?? new FitDecoder())->decode($bytes);
        if ($decoded['point_count'] < 2) {
            return ['status' => 'skipped', 'reason' => 'no_track'];
        }

        $result = $this->routes->createOrAddVersion(
            userId: $userId,
            title: 'Wahoo-Fahrt ' . $workoutId,
            description: 'Importiert aus Wahoo (Workout ' . $workoutId . ').',
            visibility: 'private',
            source: 'wahoo',
            clientRouteUuid: $clientUuid,
            payload: $decoded['geojson'],
            tags: ['wahoo'],
        );

        // Sensor-Aggregate aus der FIT persistieren: Der GeoJSON-Payload trägt keine
        // GPX-Sensor-Tags, daher greift SensorMetricsParser (GPX) nicht — wir setzen
        // die vom Gerät berechneten Session-Werte direkt. Interne Route-ID über die
        // deterministische client_route_uuid (die public-Form liefert keine interne id).
        $routeId = $this->routeIdByClientUuid($userId, $clientUuid);
        if ($routeId !== null) {
            $a = $decoded['aggregates'];
            $this->routeRepo->updateSensorMetrics($routeId, new SensorMetrics(
                avgPowerW: $a['avg_power_w'],
                maxPowerW: $a['max_power_w'],
                avgCadenceRpm: $a['avg_cadence_rpm'],
                avgPedalBalancePct: null,
                avgHeartRateBpm: $a['avg_heart_rate_bpm'],
                maxHeartRateBpm: $a['max_heart_rate_bpm'],
            ));
        }

        return ['status' => 'imported'];
    }

    /** Deterministische UUID (CHAR(36)) aus der Workout-ID — Import-Idempotenz. */
    public static function workoutUuid(string $workoutId): string
    {
        $hex = md5('wahoo:' . $workoutId);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    private function routeExists(int $userId, string $clientUuid): bool
    {
        return $this->routeIdByClientUuid($userId, $clientUuid) !== null;
    }

    private function routeIdByClientUuid(int $userId, string $clientUuid): ?int
    {
        $stmt = Db::pdo()->prepare('SELECT id FROM routes WHERE user_id = ? AND client_route_uuid = ? LIMIT 1');
        $stmt->execute([$userId, $clientUuid]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    // -----------------------------------------------------------------
    // intern
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $tokens */
    private function persistConnection(int $userId, array $tokens): void
    {
        $now = Clock::nowUtcString();
        $expiresAt = isset($tokens['expires_at'])
            ? gmdate('Y-m-d H:i:s', (int)$tokens['expires_at'])
            : null;

        $accessEnc  = $this->crypto->encrypt((string)$tokens['access_token']);
        $refreshEnc = $this->crypto->encrypt((string)$tokens['refresh_token']);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO oauth_connections
                (user_id, provider, provider_user_id, access_token_enc, refresh_token_enc, scope, expires_at, created_at, updated_at)
             VALUES (?, "wahoo", ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                provider_user_id = VALUES(provider_user_id),
                access_token_enc = VALUES(access_token_enc),
                refresh_token_enc = VALUES(refresh_token_enc),
                scope = VALUES(scope),
                expires_at = VALUES(expires_at),
                updated_at = VALUES(updated_at)'
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, (string)($tokens['wahoo_user_id'] ?? ''));
        $stmt->bindValue(3, $accessEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $refreshEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(5, $tokens['scope'] ?? null);
        $stmt->bindValue(6, $expiresAt);
        $stmt->bindValue(7, $now);
        $stmt->bindValue(8, $now);
        $stmt->execute();
    }

    /** @return array<string,mixed>|null */
    private function connectionRow(int $userId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT provider_user_id, access_token_enc, refresh_token_enc, scope, expires_at, created_at
               FROM oauth_connections WHERE user_id = ? AND provider = "wahoo" LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Frisches Access-Token (mit 60 s Puffer refreshen). Öffentlich, damit der
     * Import-/Webhook-Pfad (Phase C–E) es wiederverwenden kann.
     *
     * @param array<string,mixed> $conn
     */
    public function freshAccessToken(int $userId, array $conn): string
    {
        $expiresAt = $conn['expires_at'] !== null ? strtotime((string)$conn['expires_at'] . ' UTC') : 0;
        if ($expiresAt > time() + 60) {
            return $this->crypto->decrypt((string)$conn['access_token_enc']);
        }
        $refresh = $this->crypto->decrypt((string)$conn['refresh_token_enc']);
        $new = $this->client->refreshToken($refresh);
        $this->persistConnection($userId, [
            'wahoo_user_id' => $conn['provider_user_id'],
            'access_token'  => $new['access_token'],
            'refresh_token' => $new['refresh_token'],
            'scope'         => $conn['scope'] ?? null,
            'expires_at'    => $new['expires_at'],
        ]);
        return $new['access_token'];
    }
}
