<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Game\Admin\GameModerationService;
use App\Game\GameConfig;
use Tests\IntegrationTestCase;

/**
 * Geschwindigkeits-Heuristik der Review-Queue: Pässe mit Ø-Kanten-Tempo über
 * mod_max_speed_kmh werden markiert; invalidierte + langsamere ignoriert.
 */
final class SuspiciousSpeedTest extends IntegrationTestCase
{
    private function seedPass(int $userId, int $edgeId, ?float $speed, bool $invalid = false): void
    {
        // FK-Checks aus: kein echter game_edge/game_claimant/route nötig fürs Heuristik-Query.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $this->pdo->prepare(
            'INSERT INTO game_edge_pass
                (edge_id, claimant_id, user_id, route_id, ridden_on, ridden_at, avg_speed_kmh, invalidated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $edgeId, $userId, $userId, 1, '2026-07-01', '2026-07-01 10:00:00.000',
            $speed, $invalid ? '2026-07-01 11:00:00.000' : null,
        ]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testFlagsFastNonInvalidatedPasses(): void
    {
        $a = $this->createUser('fastguy', 'a@test.local');
        $b = $this->createUser('rocket', 'b@test.local');
        // Default mod_max_speed_kmh = 60.
        $this->seedPass($a, 1, 75.0);          // verdächtig
        $this->seedPass($a, 2, 50.0);          // ok (unter Schwelle)
        $this->seedPass($a, 3, 90.0, true);    // invalidiert → ignoriert
        $this->seedPass($b, 4, 65.0);          // verdächtig

        $svc = new GameModerationService($this->pdo, new GameConfig($this->pdo));
        $rows = $svc->suspiciousSpeed(50);

        $this->assertCount(2, $rows);
        $this->assertSame(75.0, $rows[0]['avg_speed_kmh']);   // absteigend sortiert
        $this->assertSame($a, $rows[0]['user_id']);
        $this->assertSame(65.0, $rows[1]['avg_speed_kmh']);
        $this->assertSame($b, $rows[1]['user_id']);
    }
}
