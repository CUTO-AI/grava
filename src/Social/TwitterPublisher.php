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

    public function publish(string $text): PublishResult
    {
        if (!$this->usable()) {
            return PublishResult::failure('twitter_not_configured');
        }

        $body = json_encode(['text' => $text], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
     * Baut den OAuth-1.0a-`Authorization`-Header. Bei JSON-Body fließt der Body
     * NICHT in die Signaturbasis ein (nur oauth_*-Parameter, da keine
     * Query-Parameter vorhanden sind).
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
