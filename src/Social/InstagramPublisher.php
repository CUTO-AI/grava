<?php
declare(strict_types=1);

namespace App\Social;

/**
 * Instagram-Adapter (Instagram_Automation_Concept.md §5) via Meta Graph API
 * (Content Publishing). Zwei-Schritt: Media-Container anlegen (mit `image_url`
 * + `caption`) → publishen. Instagram lädt das Bild von der ÖFFENTLICHEN URL
 * (kein Byte-Upload) — daher wird $imageUrl genutzt, nicht $imagePng.
 *
 * Voraussetzung: IG-Business-/Creator-Account + Meta-App mit
 * `instagram_content_publish` (App Review) + long-lived Token. Wirft nie.
 */
final class InstagramPublisher implements Publisher
{
    private const BASE = 'https://graph.facebook.com';

    public function __construct(
        private readonly string $igUserId,
        private readonly string $accessToken,
        private readonly string $graphVersion = 'v21.0',
    ) {}

    public function channel(): string
    {
        return 'instagram';
    }

    public function usable(): bool
    {
        return $this->igUserId !== '' && $this->accessToken !== '';
    }

    public function verify(): array
    {
        if (!$this->usable()) {
            return ['ok' => false, 'handle' => null, 'error' => 'instagram_not_configured'];
        }
        $url = self::BASE . '/' . $this->graphVersion . '/' . $this->igUserId
            . '?fields=username&access_token=' . rawurlencode($this->accessToken);
        [$status, $body, $err] = $this->httpGet($url);
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'handle' => null, 'error' => 'HTTP ' . $status . ' ' . $err . ' ' . substr($body, 0, 200)];
        }
        $decoded = json_decode($body, true);
        $handle  = is_array($decoded) ? (string)($decoded['username'] ?? '') : '';
        return ['ok' => true, 'handle' => $handle !== '' ? $handle : null, 'error' => null];
    }

    public function publish(string $text, ?string $imagePng = null, ?string $imageUrl = null): PublishResult
    {
        if (!$this->usable()) {
            return PublishResult::failure('instagram_not_configured');
        }
        if ($imageUrl === null || $imageUrl === '') {
            // Instagram-Feed erfordert ein Bild; ohne öffentliche Card-URL kein Post.
            return PublishResult::failure('instagram_needs_image_url');
        }

        // Schritt 1: Media-Container anlegen.
        $createUrl = self::BASE . '/' . $this->graphVersion . '/' . $this->igUserId . '/media';
        [$s1, $b1, $e1] = $this->httpPost($createUrl, [
            'image_url'    => $imageUrl,
            'caption'      => $text,
            'access_token' => $this->accessToken,
        ]);
        if ($s1 < 200 || $s1 >= 300) {
            return PublishResult::failure('ig_container_error (HTTP ' . $s1 . ') ' . $e1, substr($b1, 0, 300));
        }
        $creationId = (string)(json_decode($b1, true)['id'] ?? '');
        if ($creationId === '') {
            return PublishResult::failure('ig_no_creation_id', substr($b1, 0, 300));
        }

        // Schritt 2: Container publishen.
        $publishUrl = self::BASE . '/' . $this->graphVersion . '/' . $this->igUserId . '/media_publish';
        [$s2, $b2, $e2] = $this->httpPost($publishUrl, [
            'creation_id'  => $creationId,
            'access_token' => $this->accessToken,
        ]);
        if ($s2 < 200 || $s2 >= 300) {
            return PublishResult::failure('ig_publish_error (HTTP ' . $s2 . ') ' . $e2, substr($b2, 0, 300));
        }
        $mediaId = (string)(json_decode($b2, true)['id'] ?? '');
        return PublishResult::ok($mediaId !== '' ? $mediaId : null, substr($b2, 0, 300));
    }

    /** @return array{0:int,1:string,2:string} [status, body, error] */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$status, $body, $err];
    }

    /** @param array<string,string> $fields @return array{0:int,1:string,2:string} */
    private function httpPost(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$status, $body, $err];
    }
}
