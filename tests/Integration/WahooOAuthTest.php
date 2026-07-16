<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Integrations\Wahoo\FakeWahooClient;
use App\Integrations\Wahoo\WahooException;
use App\Integrations\Wahoo\WahooService;
use App\Support\Crypto;
use Tests\IntegrationTestCase;

/**
 * Wahoo-Integration Phase B: OAuth-Flow (authorize → callback → status →
 * disconnect) gegen den Fake-Client, ohne Netz/Credentials.
 */
final class WahooOAuthTest extends IntegrationTestCase
{
    private Crypto $crypto;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crypto = new Crypto(base64_encode(str_repeat("\x09", 32)));
        $this->userId = $this->createUser('wahoo-rider');
    }

    private function service(bool $fake = true): WahooService
    {
        return new WahooService(
            new FakeWahooClient(),
            $this->crypto,
            $fake ? '' : 'real-client-id',
            'http://localhost/auth/wahoo/callback',
            $fake,
            'http://localhost',
        );
    }

    public function testAuthorizeUrlFakePointsAtOwnCallbackAndStoresState(): void
    {
        $url = $this->service()->authorizeUrl($this->userId, 'mobile', 'grava://wahoo-connected');
        $this->assertStringContainsString('/auth/wahoo/callback', $url);
        $this->assertStringContainsString('state=', $url);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM oauth_states WHERE user_id = ? AND provider = "wahoo"');
        $count->execute([$this->userId]);
        $this->assertSame(1, (int)$count->fetchColumn());
    }

    public function testAuthorizeUrlRealHasWahooEndpointAndScopes(): void
    {
        $url = $this->service(false)->authorizeUrl($this->userId, 'web');
        $this->assertStringContainsString('https://api.wahooligan.com/oauth/authorize', $url);
        $this->assertStringContainsString('user_read', $url);
        $this->assertStringContainsString('workouts_read', $url);
        $this->assertStringContainsString('offline_data', $url);
    }

    public function testCallbackPersistsConnectionThenDisconnect(): void
    {
        $svc = $this->service();

        // Nicht verbunden.
        $this->assertFalse($svc->status($this->userId)['connected']);

        // Authorize → State extrahieren → Callback (mobile: session-los).
        $url = $svc->authorizeUrl($this->userId, 'mobile', 'grava://wahoo-connected');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $res = $svc->handleCallback((string)$q['state'], (string)$q['code'], null);
        $this->assertSame($this->userId, $res['user_id']);
        $this->assertSame('grava://wahoo-connected', $res['return_to']);

        // Verbunden — Wahoo-User-ID + Scope aus dem Fake-Client.
        $status = $svc->status($this->userId);
        $this->assertTrue($status['connected']);
        $this->assertSame('91000001', $status['wahoo_user_id']);
        $this->assertStringContainsString('offline_data', (string)$status['scope']);

        // State ist single-use — zweiter Callback scheitert.
        $this->expectException(WahooException::class);
        $svc->handleCallback((string)$q['state'], (string)$q['code'], null);
    }

    public function testDisconnectRemovesConnection(): void
    {
        $svc = $this->service();
        $url = $svc->authorizeUrl($this->userId, 'mobile', 'grava://wahoo-connected');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $svc->handleCallback((string)$q['state'], (string)$q['code'], null);
        $this->assertTrue($svc->status($this->userId)['connected']);

        $svc->disconnect($this->userId);
        $this->assertFalse($svc->status($this->userId)['connected']);
    }
}
