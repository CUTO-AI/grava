<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Game\Admin\AnalyticsAdminService;
use App\Support\Clock;
use Tests\IntegrationTestCase;

/**
 * Auswertungen (GameAdmin_Concept.md Phase 2): Zeitreihen, Quellen-Aufteilung
 * und wöchentliche Signup-Kohorten-Retention.
 */
final class AnalyticsAdminServiceTest extends IntegrationTestCase
{
    private function seedUser(string $createdAt): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (public_id, email, email_verified_at, password_hash, status, created_at, updated_at)
             VALUES (?, ?, ?, "x", "active", ?, ?)'
        )->execute([self::uuid4(), 'u' . bin2hex(random_bytes(4)) . '@t.local', $createdAt, $createdAt, $createdAt]);
        return (int)$this->pdo->lastInsertId();
    }

    private function seedRoute(int $userId, string $createdAt, string $source = 'app'): void
    {
        $this->pdo->prepare(
            'INSERT INTO routes (public_id, user_id, title, visibility, source, centroid, created_at, updated_at)
             VALUES (?, ?, "R", "private", ?, ST_SRID(POINT(8.5, 49.5), 4326), ?, ?)'
        )->execute([self::uuid4(), $userId, $source, $createdAt, $createdAt]);
    }

    private function at(string $modify): string
    {
        return Clock::nowUtc()->modify($modify)->format('Y-m-d H:i:s');
    }

    public function testTimeseriesAndSources(): void
    {
        $today = Clock::nowUtc()->format('Y-m-d H:i:s');
        $u1 = $this->seedUser($today);
        $this->seedUser($today);
        $this->seedUser($this->at('-40 days'));   // außerhalb 30T

        $svc = new AnalyticsAdminService($this->pdo);
        $signups = $svc->signupsPerDay(30);
        $todayKey = Clock::nowUtc()->format('Y-m-d');
        $todayRow = array_values(array_filter($signups, static fn($r) => $r['d'] === $todayKey));
        $this->assertSame(2, $todayRow[0]['n']);

        $this->seedRoute($u1, $today, 'app');
        $this->seedRoute($u1, $today, 'app');
        $this->seedRoute($u1, $today, 'strava');
        $sources = $svc->sourceBreakdown(30);
        $this->assertSame(2, $sources['app']);
        $this->assertSame(1, $sources['strava']);
    }

    public function testRetentionCohortsOffsets(): void
    {
        // Signup vor 10 Tagen → Kohorte; Fahrt am Signup-Tag (W0) + heute (W1, da 10/7=1).
        $signup = $this->at('-10 days');
        $uid = $this->seedUser($signup);
        $this->seedRoute($uid, $signup);                  // week_offset 0
        $this->seedRoute($uid, $this->at('-1 hour'));     // ~10 Tage später → week_offset 1

        $svc = new AnalyticsAdminService($this->pdo);
        $ret = $svc->retentionCohorts(8, 4);
        $this->assertNotEmpty($ret['cohorts']);
        $c = $ret['cohorts'][0];
        $this->assertSame(1, $c['size']);
        $this->assertSame(1, $c['retained'][0] ?? 0);
        $this->assertSame(1, $c['retained'][1] ?? 0);
    }
}
