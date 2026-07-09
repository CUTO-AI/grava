<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\Crew\CrewRepository;
use App\Game\CrewLeaderboardService;
use App\Game\GameRepository;
use Tests\IntegrationTestCase;

/**
 * Globale Crew-Rangliste (all-time, gehaltene Revierlänge) — CrewLeaderboardService
 * über CrewRepository::topByHeldLength(). Prüft Sortierung, Ausschluss von Crews
 * ohne Revier und die deckungsgleiche Rang-Semantik zum Crew-Weltrang.
 */
final class CrewGlobalLeaderboardTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private CrewRepository $crews;
    private CrewLeaderboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo  = new GameRepository($this->pdo);
        $this->crews = new CrewRepository($this->pdo);
        $this->svc   = new CrewLeaderboardService($this->crews);
    }

    /** @param list<int> $memberUserIds @return array{claimant_id:int,crew_id:int} */
    private function makeCrew(string $slug, string $joinCode, string $name, array $memberUserIds): array
    {
        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)')->execute();
        $claimantId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code) VALUES (?, ?, ?, ?, ?)'
        )->execute([$claimantId, $name, $slug, $memberUserIds[0], $joinCode]);
        $crewId = (int)$this->pdo->lastInsertId();
        foreach ($memberUserIds as $i => $uid) {
            $this->pdo->prepare('INSERT INTO game_crew_member (user_id, crew_id, role) VALUES (?, ?, ?)')
                ->execute([$uid, $crewId, $i === 0 ? 'captain' : 'member']);
        }
        return ['claimant_id' => $claimantId, 'crew_id' => $crewId];
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

    public function testRanksCrewsByHeldLengthAndExcludesEmpty(): void
    {
        $u1 = $this->createUser('crewleadA');
        $u2 = $this->createUser('crewmateA');
        $u3 = $this->createUser('crewleadB');
        $u4 = $this->createUser('crewleadC');

        $crewA = $this->makeCrew('crew-a', 'JOINAAA1', 'Alpha', [$u1, $u2]); // 300 m
        $crewB = $this->makeCrew('crew-b', 'JOINBBB2', 'Bravo', [$u3]);      // 100 m
        $this->makeCrew('crew-c', 'JOINCCC3', 'Charlie', [$u4]);            // 0 m -> raus

        $this->ownedEdge(5101, 510, 200.0, $crewA['claimant_id']);
        $this->ownedEdge(5102, 512, 100.0, $crewA['claimant_id']);
        $this->ownedEdge(5103, 514, 100.0, $crewB['claimant_id']);

        $res = $this->svc->leaderboard();
        $entries = $res['entries'];

        // Nur A und B (C hat kein Revier).
        $this->assertCount(2, $entries);

        // Rang 1: Alpha mit 300 m / 2 Kanten.
        $this->assertSame(1, $entries[0]['rank']);
        $this->assertSame('crew-a', $entries[0]['slug']);
        $this->assertSame('Alpha', $entries[0]['name']);
        $this->assertSame(300.0, $entries[0]['held_length_m']);
        $this->assertSame(2, $entries[0]['held_edges']);
        $this->assertSame(2, $entries[0]['member_count']);

        // Rang 2: Bravo mit 100 m / 1 Kante.
        $this->assertSame(2, $entries[1]['rank']);
        $this->assertSame('crew-b', $entries[1]['slug']);
        $this->assertSame(100.0, $entries[1]['held_length_m']);
        $this->assertSame(1, $entries[1]['held_edges']);
    }
}
