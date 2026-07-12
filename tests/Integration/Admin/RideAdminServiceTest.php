<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Game\Admin\RideAdminService;
use PDO;
use Tests\IntegrationTestCase;

/**
 * Fahrten-Verwaltung (GameAdmin_Concept.md Modul C): Suche/Filter, Detail,
 * Besitzer-Lookup und Soft-Delete.
 */
final class RideAdminServiceTest extends IntegrationTestCase
{
    private function routeId(string $publicId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM routes WHERE public_id = ?');
        $stmt->execute([$publicId]);
        return (int)$stmt->fetchColumn();
    }

    public function testSearchDetailAndSoftDelete(): void
    {
        $uid = $this->createUser('rider', 'rider@test.local');
        $r1 = $this->routeId($this->createRoute($uid));
        $r2 = $this->routeId($this->createRoute($uid));
        $svc = new RideAdminService($this->pdo);

        $all = $svc->search('', '', 'created_at', 'desc', 50, 0);
        $this->assertCount(2, $all);

        // Suche nach User-E-Mail + Quelle (createRoute nutzt source=app).
        $this->assertCount(2, $svc->search('rider@test', '', 'created_at', 'desc', 50, 0));
        $this->assertCount(2, $svc->search('', 'app', 'created_at', 'desc', 50, 0));
        $this->assertCount(0, $svc->search('', 'strava', 'created_at', 'desc', 50, 0));

        $detail = $svc->detail($r1);
        $this->assertNotNull($detail);
        $this->assertFalse($detail['in_game']);
        $this->assertSame(0, $detail['game_edges']);

        $this->assertSame($uid, $svc->owner($r1)['user_id']);

        $svc->softDelete($r1);
        $this->assertCount(1, $svc->search('', '', 'created_at', 'desc', 50, 0));
        $this->assertSame($r2, (int)$svc->search('', '', 'created_at', 'desc', 50, 0)[0]['id']);
    }

    public function testInvalidateGameWithNoPassesReturnsZero(): void
    {
        $uid = $this->createUser(null, 'r2@test.local');
        $rid = $this->routeId($this->createRoute($uid));
        $svc = new RideAdminService($this->pdo);
        $this->assertSame(0, $svc->invalidateGame($rid, 1, 'test'));
    }
}
