<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameRepository;
use Tests\IntegrationTestCase;

/**
 * Globale Solo-Rangliste (all-time) nach gehaltener Revierlänge —
 * GameRepository::topRidersByHeldLength(). Prüft Sortierung, Kanten-/Längen-
 * Summen und dass nur Fahrer-Claimants (nicht Crews) gezählt werden.
 */
final class RiderGlobalLeaderboardTest extends IntegrationTestCase
{
    private GameRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
    }

    private function ownedEdge(int $wayId, int $nodeBase, float $lengthM, int $claimantId): void
    {
        $a = $this->repo->upsertNode($nodeBase, 47.12, 9.65);
        $b = $this->repo->upsertNode($nodeBase + 1, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $edgeId = $this->repo->upsertEdge($wayId, $a, $b, $lengthM, $geom, null, 47.12, 9.65, 47.13, 9.66);
        $this->pdo->prepare('UPDATE game_edge SET owner_claimant_id = ? WHERE id = ?')
            ->execute([$claimantId, $edgeId]);
    }

    public function testRanksRidersByHeldLength(): void
    {
        $u1 = $this->createUser('riderA');
        $u2 = $this->createUser('riderB');
        $c1 = $this->repo->riderClaimantId($u1); // 300 m
        $c2 = $this->repo->riderClaimantId($u2); // 100 m

        // Eine Crew-Kante darf NICHT in der Solo-Rangliste auftauchen.
        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)')->execute();
        $crewClaimant = (int)$this->pdo->lastInsertId();

        $this->ownedEdge(6101, 610, 200.0, $c1);
        $this->ownedEdge(6102, 612, 100.0, $c1);
        $this->ownedEdge(6103, 614, 100.0, $c2);
        $this->ownedEdge(6104, 616, 999.0, $crewClaimant);

        $rows = $this->repo->topRidersByHeldLength(100);

        $this->assertCount(2, $rows, 'Nur Fahrer-Claimants, keine Crew.');
        $this->assertSame('riderA', $rows[0]['handle']);
        $this->assertSame(300.0, $rows[0]['held_length_m']);
        $this->assertSame(2, $rows[0]['held_edges']);
        $this->assertSame('riderB', $rows[1]['handle']);
        $this->assertSame(100.0, $rows[1]['held_length_m']);
        $this->assertSame(1, $rows[1]['held_edges']);
    }
}
