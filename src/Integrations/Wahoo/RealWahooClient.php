<?php
declare(strict_types=1);

namespace App\Integrations\Wahoo;

/**
 * Echter Wahoo-Cloud-API-Client (OAuth2 + Workouts/FIT) via cURL.
 *
 * Wird nur instanziiert, wenn WAHOO_CLIENT_ID/SECRET konfiguriert sind und
 * WAHOO_FAKE nicht aktiv ist. Mangels Test-Credentials ist dieser Pfad NICHT
 * Teil des automatisierten Smoke-Tests (dort läuft der {@see FakeWahooClient}).
 *
 * Basis: https://api.wahooligan.com. OAuth-Token laufen nach ~2 h ab; die
 * Token-Antwort liefert `expires_in` (Sekunden) → wir rechnen auf einen
 * absoluten `expires_at` (Unix-ts) um, damit der Service wie bei Strava
 * refreshen kann.
 *
 * HINWEIS: Die genauen Response-Schemata von /v1/workouts bzw. der FIT-URL
 * (`file.url`) werden erst mit Sandbox-Zugang final verifiziert
 * (Wahoo_Integration_Concept.md §11). Die Extraktion ist daher defensiv.
 */
final class RealWahooClient implements WahooClient
{
    private const BASE      = 'https://api.wahooligan.com';
    private const TOKEN_URL = self::BASE . '/oauth/token';
    private const API_BASE  = self::BASE . '/v1';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
    ) {}

    public function exchangeCode(string $code): array
    {
        $res = $this->postForm(self::TOKEN_URL, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);
        $tokens = $this->tokenFields($res);
        // Wahoo-User-ID separat abrufen (Token-Antwort enthält sie nicht sicher).
        $wahooUserId = '';
        try {
            $user = $this->get(self::API_BASE . '/user', $tokens['access_token']);
            $wahooUserId = (string)($user['id'] ?? '');
        } catch (WahooException) {
            // Nicht fatal: der Service kann auch ohne User-ID persistieren.
        }
        return $tokens + ['wahoo_user_id' => $wahooUserId];
    }

    public function refreshToken(string $refreshToken): array
    {
        $res = $this->postForm(self::TOKEN_URL, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
        $t = $this->tokenFields($res);
        return [
            'access_token'  => $t['access_token'],
            'refresh_token' => $t['refresh_token'] !== '' ? $t['refresh_token'] : $refreshToken,
            'expires_at'    => $t['expires_at'],
        ];
    }

    public function listWorkouts(string $accessToken, int $perPage = 30, int $page = 1): array
    {
        $url = self::API_BASE . '/workouts?per_page=' . $perPage . '&page=' . max(1, $page);
        $res = $this->get($url, $accessToken);
        // Wahoo liefert die Liste unter `workouts` (paginierter Envelope).
        $list = $res['workouts'] ?? (array_is_list($res) ? $res : []);
        $out = [];
        foreach ((array)$list as $w) {
            if (!is_array($w)) {
                continue;
            }
            $out[] = [
                'id'           => (string)($w['id'] ?? ''),
                'name'         => isset($w['name']) ? (string)$w['name'] : null,
                'starts'       => isset($w['starts']) ? (string)$w['starts'] : null,
                'workout_type' => isset($w['workout_type_id']) ? (string)$w['workout_type_id'] : null,
            ];
        }
        return $out;
    }

    public function getWorkoutSummary(string $accessToken, string $workoutId): array
    {
        $res = $this->get(self::API_BASE . '/workouts/' . rawurlencode($workoutId), $accessToken);
        // FIT-URL steckt in der Workout-Zusammenfassung: workout_summary.file.url
        // (Feldnamen im Sandbox final verifizieren).
        $summary = $res['workout_summary'] ?? [];
        $file    = is_array($summary) ? ($summary['file'] ?? []) : [];
        $fitUrl  = is_array($file) && isset($file['url']) ? (string)$file['url'] : null;
        return [
            'fit_file_url' => ($fitUrl !== null && $fitUrl !== '') ? $fitUrl : null,
            'starts'       => isset($res['starts']) ? (string)$res['starts'] : null,
        ];
    }

    public function downloadFit(string $accessToken, string $fitFileUrl): string
    {
        // Die FIT-URL ist typischerweise eine vorsignierte (S3-)URL ohne
        // Auth-Header — daher OHNE Authorization anfragen, um Ablehnung zu
        // vermeiden. Rohe Bytes zurückgeben (kein JSON).
        $ch = curl_init($fitFileUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status >= 400) {
            throw new WahooException('fit_download_failed',
                'FIT-Download fehlgeschlagen (HTTP ' . $status . '): ' . $err, 502);
        }
        return (string)$body;
    }

    public function deauthorize(string $accessToken): void
    {
        // Best-effort-Cleanup: Fehler hier dürfen das Trennen nicht blockieren
        // (lokal löschen wir die Verbindung ohnehin). Endpunkt im Sandbox prüfen.
        try {
            $ch = curl_init(self::API_BASE . '/permissions');
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'DELETE',
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
            // ignorieren
        }
    }

    /**
     * Gemeinsame Token-Felder aus einer /oauth/token-Antwort. Wahoo liefert
     * `expires_in` (Sekunden) → absoluter `expires_at`.
     *
     * @param array<string,mixed> $res
     * @return array{access_token:string, refresh_token:string, expires_at:int, scope:?string}
     */
    private function tokenFields(array $res): array
    {
        $expiresIn = isset($res['expires_in']) ? (int)$res['expires_in'] : 7200;
        return [
            'access_token'  => (string)($res['access_token'] ?? ''),
            'refresh_token' => (string)($res['refresh_token'] ?? ''),
            'expires_at'    => time() + max(60, $expiresIn),
            'scope'         => isset($res['scope']) ? (string)$res['scope'] : null,
        ];
    }

    /**
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    private function postForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        return $this->exec($ch);
    }

    /** @return array<string,mixed> */
    private function get(string $url, string $accessToken, int $timeout = 15): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        return $this->exec($ch);
    }

    /** @return array<string,mixed> */
    private function exec(\CurlHandle $ch): array
    {
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($status === 429) {
            throw new WahooException('rate_limit', 'Wahoo-Limit erreicht, bitte später erneut.', 429);
        }
        if ($body === false || $status >= 400) {
            throw new WahooException('wahoo_api_error',
                'Wahoo-API-Fehler (HTTP ' . $status . '): ' . $err, 502);
        }
        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
