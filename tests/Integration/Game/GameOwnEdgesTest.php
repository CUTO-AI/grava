<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameConfig;
use App\Game\GameReadService;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/** Eigene-Kanten-Cache (OwnEdgesCache_Concept.md): Voll-Snapshot + Delta. */
final class GameOwnEdgesTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameReadService $read;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->read = new GameReadService($this->repo, new GameConfig($this->pdo));
    }

    public function testSnapshotListsHeldEdgesWithGeometry(): void
    {
        $me = $this->createUser('owner');
        $claimant = $this->repo->riderClaimantId($me);
        $e1 = $this->makeOwnedEdge(6001, $claimant, '-3 days');
        $e2 = $this->makeOwnedEdge(6002, $claimant, '-1 days');
        $this->makeOwnedEdge(6003, $claimant + 999, '-1 days'); // fremd → nicht enthalten

        $snap = $this->read->ownEdgesSnapshot($me);
        $this->assertSame($claimant, $snap['claimant_id']);
        $this->assertSame(2, $snap['held_count']);
        $ids = array_column($snap['edges'], 'id');
        sort($ids);
        $this->assertSame([$e1, $e2], $ids);
        $this->assertSame('LineString', $snap['edges'][0]['geom']['type']);
        $this->assertNotEmpty($snap['as_of']);
    }

    public function testChangesReturnsGainedSinceAndFiltersReclaimedFromLost(): void
    {
        $me = $this->createUser('delta');
        $rival = $this->createUser('rival');
        $claimant = $this->repo->riderClaimantId($me);

        $old   = $this->makeOwnedEdge(6010, $claimant, '-10 days');  // vor `since` → kein Delta
        $fresh = $this->makeOwnedEdge(6011, $claimant, '-1 hour');   // nach `since` → gained
        $lost  = $this->makeOwnedEdge(6012, $claimant + 999, '-1 hour'); // an rival verloren
        $back  = $this->makeOwnedEdge(6013, $claimant, '-30 minutes');   // verloren + zurückerobert

        $this->insertTakenEvent($me, $rival, $lost, '-2 hours');
        $this->insertTakenEvent($me, $rival, $back, '-2 hours');

        $since = new DateTimeImmutable('-1 day', new DateTimeZone('UTC'));
        $delta = $this->read->ownEdgeChanges($me, $since);

        $this->assertFalse($delta['resync']);
        $gainedIds = array_column($delta['gained'], 'id');
        sort($gainedIds);
        $this->assertSame([$fresh, $back], $gainedIds, 'owner_since nach `since` → gained (inkl. Rückeroberung).');
        $this->assertNotContains($old, $gainedIds);
        $this->assertSame([$lost], $delta['lost_ids'], 'Zurückeroberte Kante steht NICHT in lost_ids (gained gewinnt).');
        $this->assertSame(3, $delta['held_count']);
    }

    public function testChangesRequestsResyncForAncientSince(): void
    {
        $me = $this->createUser('ancient');
        $since = new DateTimeImmutable('-90 days', new DateTimeZone('UTC'));
        $delta = $this->read->ownEdgeChanges($me, $since);
        $this->assertTrue($delta['resync']);
        $this->assertSame([], $delta['gained']);
        $this->assertSame([], $delta['lost_ids']);
    }

    // -- Helfer ----------------------------------------------------------

    /** Legt eine Kante an und setzt Besitz direkt (owner_since relativ zu jetzt). */
    private function makeOwnedEdge(int $wayId, int $claimantId, string $ownerSinceRel): int
    {
        $a = $this->repo->upsertNode($wayId * 10, 47.12, 9.65);
        $b = $this->repo->upsertNode($wayId * 10 + 1, 47.13, 9.66);
        $json = json_encode(['type' => 'LineString',
                             'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $id = $this->repo->upsertEdge($wayId, $a, $b, 120.0, $json, null, 47.12, 9.65, 47.13, 9.66);
        // Fremd-Claimant ggf. anlegen (FK), dann Besitz setzen.
        $this->pdo->prepare('INSERT IGNORE INTO game_claimant (id, type, user_id) VALUES (?, "group", NULL)')
            ->execute([$claimantId]);
        $ts = (new DateTimeImmutable($ownerSinceRel, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $this->pdo->prepare('UPDATE game_edge SET owner_claimant_id = ?, owner_since = ?, last_pass_at = ? WHERE id = ?')
            ->execute([$claimantId, $ts, $ts, $id]);
        return $id;
    }

    /** `edge_taken`-Ledger-Eintrag: $victim verliert $edgeId an $actor. */
    private function insertTakenEvent(int $victim, int $actor, int $edgeId, string $createdRel): void
    {
        $ts = (new DateTimeImmutable($createdRel, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
        $this->pdo->prepare(
            'INSERT INTO game_event (type, user_id, actor_user_id, edge_id, ridden_on, created_at)
             VALUES ("edge_taken", ?, ?, ?, CURDATE(), ?)'
        )->execute([$victim, $actor, $edgeId, $ts]);
    }
}
