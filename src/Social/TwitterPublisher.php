<?php
declare(strict_types=1);

namespace App\Social;

/**
 * X/Twitter-Adapter (Konzept §6). Postet via API v2 `POST /2/tweets`,
 * signiert per OAuth 1.0a User-Context (HMAC-SHA1) — der einfachste Weg für
 * EINEN eigenen Marken-Account (@cyberride, E9): vier statische Secrets aus
 * der .env, kein Token-Refresh-Flow nötig.
 *
 * Curl + Fehlerbehandlung folgen dem Muster von RealStravaClient/ApnsHttpClient
 * (native curl, kein Guzzle). Wirft nie — Fehler landen im PublishResult.
 */
final class TwitterPublisher implements Publisher
{
    private const TWEETS_URL = 'https://api.twitter.com/2/tweets';
    private const MEDIA_URL  = 'https://upload.twitter.com/1.1/media/upload.json';
    private const ME_URL     = 'https://api.twitter.com/2/users/me';

    public function __construct(
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
        private readonly string $accessToken,
        private readonly string $accessTokenSecret,
    ) {}

    public function channel(): string
    {
        return 'twitter';
    }

    /** Alle vier Credentials gesetzt? Sonst darf nur der NullPublisher laufen. */
    public function usable(): bool
    {
        return $this->consumerKey !== ''
            && $this->consumerSecret !== ''
            && $this->accessToken !== ''
            && $this->accessTokenSecret !== '';
    }

    public function publish(string $text, ?string $imagePng = null): PublishResult
    {
        if (!$this->usable()) {
            return PublishResult::failure('twitter_not_configured');
        }

        // Optional eine Media-Card hochladen. Schlägt der Upload fehl, posten wir
        // trotzdem text-only weiter (Bild ist Beiwerk, kein harter Blocker).
        $mediaId = null;
        if ($imagePng !== null && $imagePng !== '') {
            $mediaId = $this->uploadMedia($imagePng);
        }

        $payload = ['text' => $text];
        if ($mediaId !== null) {
            $payload['media'] = ['media_ids' => [$mediaId]];
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return PublishResult::failure('json_encode_failed');
        }

        $auth = $this->authorizationHeader('POST', self::TWEETS_URL);

        $ch = curl_init(self::TWEETS_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $auth,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        $snippet = substr((string)$resp, 0, 400);

        if ($resp === false) {
            return PublishResult::failure('curl_error: ' . $err, $snippet);
        }
        if ($status === 429) {
            return PublishResult::failure('rate_limit (HTTP 429)', $snippet);
        }
        if ($status < 200 || $status >= 300) {
            return PublishResult::failure('twitter_api_error (HTTP ' . $status . ')', $snippet);
        }

        $decoded = json_decode((string)$resp, true);
        $id = is_array($decoded) ? (string)($decoded['data']['id'] ?? '') : '';
        return PublishResult::ok($id !== '' ? $id : null, $snippet);
    }

    /**
     * Prüft die Credentials ohne zu posten: GET /2/users/me. Gibt bei Erfolg
     * das Handle des authentifizierten Accounts zurück (für `social:doctor`).
     *
     * @return array{ok:bool, handle:?string, error:?string}
     */
    public function verify(): array
    {
        if (!$this->usable()) {
            return ['ok' => false, 'handle' => null, 'error' => 'twitter_not_configured'];
        }
        $ch = curl_init(self::ME_URL);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: ' . $this->authorizationHeader('GET', self::ME_URL)],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $status < 200 || $status >= 300) {
            return ['ok' => false, 'handle' => null, 'error' => 'HTTP ' . $status . ' ' . $err . ' ' . substr((string)$resp, 0, 200)];
        }
        $decoded = json_decode((string)$resp, true);
        $handle  = is_array($decoded) ? (string)($decoded['data']['username'] ?? '') : '';
        return ['ok' => true, 'handle' => $handle !== '' ? $handle : null, 'error' => null];
    }

    /**
     * Lädt ein PNG als Media hoch (v1.1 media/upload, multipart). Der
     * multipart-Body fließt — wie ein JSON-Body — NICHT in die OAuth-Signatur
     * ein. Gibt die media_id oder null zurück (Fehler werden geloggt, nie
     * geworfen — der Post geht dann text-only raus).
     */
    private function uploadMedia(string $png): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cr_card_') ?: '';
        if ($tmp === '' || @file_put_contents($tmp, $png) === false) {
            error_log('twitter media: temp-Datei fehlgeschlagen');
            return null;
        }
        try {
            $ch = curl_init(self::MEDIA_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => ['media' => new \CURLFile($tmp, 'image/png', 'card.png')],
                CURLOPT_HTTPHEADER     => ['Authorization: ' . $this->authorizationHeader('POST', self::MEDIA_URL)],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
            ]);
            $resp   = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);
        } finally {
            @unlink($tmp);
        }

        if ($resp === false || $status < 200 || $status >= 300) {
            error_log("twitter media: Upload fehlgeschlagen (HTTP {$status}) {$err} " . substr((string)$resp, 0, 200));
            return null;
        }
        $decoded = json_decode((string)$resp, true);
        $id = is_array($decoded) ? (string)($decoded['media_id_string'] ?? '') : '';
        return $id !== '' ? $id : null;
    }

    /**
     * Baut den OAuth-1.0a-`Authorization`-Header. Bei JSON-/multipart-Body
     * fließt der Body NICHT in die Signaturbasis ein (nur oauth_*-Parameter, da
     * keine Query-Parameter vorhanden sind).
     */
    private function authorizationHeader(string $method, string $url): string
    {
        $oauth = [
            'oauth_consumer_key'     => $this->consumerKey,
            'oauth_nonce'            => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string)time(),
            'oauth_token'            => $this->accessToken,
            'oauth_version'          => '1.0',
        ];

        ksort($oauth);
        $paramString = implode('&', array_map(
            static fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v),
            array_keys($oauth),
            array_values($oauth),
        ));

        $base = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode($paramString);
        $key  = rawurlencode($this->consumerSecret) . '&' . rawurlencode($this->accessTokenSecret);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));

        ksort($oauth);
        $header = implode(', ', array_map(
            static fn($k, $v) => rawurlencode($k) . '="' . rawurlencode($v) . '"',
            array_keys($oauth),
            array_values($oauth),
        ));

        return 'OAuth ' . $header;
    }
}
