<?php
declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Wahoo\FakeWahooClient;
use App\Integrations\Wahoo\WahooClient;
use PHPUnit\Framework\TestCase;

/**
 * Wahoo-Integration Phase A: der Fake-Client liefert stabile Fixtures für den
 * Import-Pfad (eine Fahrt mit FIT/GPS, eine ohne → Skip).
 */
final class FakeWahooClientTest extends TestCase
{
    private WahooClient $client;

    protected function setUp(): void
    {
        $this->client = new FakeWahooClient();
    }

    public function testExchangeCodeReturnsTokensAndScope(): void
    {
        $t = $this->client->exchangeCode('abc');
        $this->assertNotSame('', $t['access_token']);
        $this->assertSame('91000001', $t['wahoo_user_id']);
        $this->assertStringContainsString('offline_data', (string)$t['scope']);
        $this->assertGreaterThan(time(), $t['expires_at']);
    }

    public function testRefreshKeepsRefreshToken(): void
    {
        $t = $this->client->refreshToken('keep-me');
        $this->assertSame('keep-me', $t['refresh_token']);
        $this->assertGreaterThan(time(), $t['expires_at']);
    }

    public function testListWorkoutsPaginatesToEmpty(): void
    {
        $page1 = $this->client->listWorkouts('tok', 30, 1);
        $this->assertCount(2, $page1);
        $this->assertSame('9100000001', $page1[0]['id']);
        $this->assertSame([], $this->client->listWorkouts('tok', 30, 2));
    }

    public function testWorkoutSummaryFitPresenceDrivesSkip(): void
    {
        $withFit = $this->client->getWorkoutSummary('tok', '9100000001');
        $this->assertNotNull($withFit['fit_file_url']);

        $withoutFit = $this->client->getWorkoutSummary('tok', '9100000002');
        $this->assertNull($withoutFit['fit_file_url']);
    }

    public function testDownloadFitIsDeterministic(): void
    {
        $url = 'https://fake.wahoo/fit/9100000001.fit';
        $this->assertSame(
            $this->client->downloadFit('tok', $url),
            $this->client->downloadFit('tok', $url),
        );
    }
}
