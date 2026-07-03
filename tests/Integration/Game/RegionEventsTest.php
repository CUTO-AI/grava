<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameEventRecorder;
use App\Game\GameEventRepository;
use App\Game\GameRepository;
use App\Support\Clock;
use PDO;
use Tests\IntegrationTestCase;

/**
 * Gebiets-Eroberung Phase D: Besitzwechsel → region_taken/region_lost-Ereignisse
 * (CityConquest_Backend_Spec.md). Prüft die Empfänger-Logik: der bisherige
 * Eigentümer verliert (region_lost), die neue Crew erobert (region_taken an die
 * Mitglieder außer dem Auslöser).
 */
final class RegionEventsTest extends IntegrationTestCase
{
    private GameEventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $repo = new GameRepository($this->pdo);
        $this->recorder = new GameEventRecorder($repo, new GameEventRepository($this->pdo));
    }

    private function riderClaimant(int $userId): int
    {
        $this->pdo->prepare("INSERT INTO game_claimant (type, user_id) VALUES ('rider', ?)")->execute([$userId]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $memberIds */
    private function crewClaimant(array $memberIds, int $ownerId): int
    {
        $this->pdo->prepare("INSERT INTO game_claimant (type, user_id) VALUES ('group', NULL)")->execute();
        $cid = (int)$this->pdo->lastInsertId();
        $now = Clock::nowUtcString();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$cid, 'Nighthawks', 'nighthawks-' . $cid, $ownerId, substr(md5((string)$cid), 0, 8), $now]);
        $crewId = (int)$this->pdo->lastInsertId();
        foreach ($memberIds as $i => $uid) {
            $this->pdo->prepare(
                'INSERT INTO game_crew_member (user_id, crew_id, role, joined_at) VALUES (?, ?, ?, ?)'
            )->execute([$uid, $crewId, $i === 0 ? 'captain' : 'member', $now]);
        }
        return $cid;
    }

    /** @return list<array<string,mixed>> */
    private function events(string $type): array
    {
        $stmt = $this->pdo->prepare('SELECT user_id, region_id, actor_user_id FROM game_event WHERE type = ? ORDER BY user_id');
        $stmt->execute([$type]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function testTakeoverEmitsLostToOldOwnerAndTakenToNewCrew(): void
    {
        $uOld = $this->createUser('old');
        $uAct = $this->createUser('actor');   // Eroberer (Crew-Mitglied)
        $uCol = $this->createUser('colleague');

        $oldClaimant = $this->riderClaimant($uOld);
        $newCrew = $this->crewClaimant([$uAct, $uCol], $uAct);
        $regionId = 4711;

        $written = $this->recorder->recordRegionChanges(
            [['region_id' => $regionId, 'level' => 8, 'old_owner' => $oldClaimant, 'new_owner' => $newCrew]],
            $uAct,
            '2026-07-03',
        );

        // region_lost an den alten (Solo-)Eigentümer.
        $lost = $this->events('region_lost');
        $this->assertCount(1, $lost);
        $this->assertSame($uOld, (int)$lost[0]['user_id']);
        $this->assertSame($regionId, (int)$lost[0]['region_id']);
        $this->assertSame($uAct, (int)$lost[0]['actor_user_id']);

        // region_taken an das Crew-Mitglied — NICHT an den Auslöser.
        $taken = $this->events('region_taken');
        $this->assertCount(1, $taken);
        $this->assertSame($uCol, (int)$taken[0]['user_id']);
        $this->assertSame($regionId, (int)$taken[0]['region_id']);

        $this->assertSame(2, $written);
    }

    public function testNoChangeEmitsNothing(): void
    {
        $u = $this->createUser('x');
        $c = $this->riderClaimant($u);
        $written = $this->recorder->recordRegionChanges(
            [['region_id' => 1, 'level' => 8, 'old_owner' => $c, 'new_owner' => $c]],
            $u,
            '2026-07-03',
        );
        $this->assertSame(0, $written);
        $this->assertSame([], $this->events('region_taken'));
        $this->assertSame([], $this->events('region_lost'));
    }

    public function testFreshConquestOfUnownedRegionOnlyNotifiesNewOwnerColleagues(): void
    {
        $uAct = $this->createUser('a');
        $uCol = $this->createUser('b');
        $newCrew = $this->crewClaimant([$uAct, $uCol], $uAct);

        // old_owner = null (vorher herrenlos) → nur region_taken, kein region_lost.
        $written = $this->recorder->recordRegionChanges(
            [['region_id' => 77, 'level' => 8, 'old_owner' => null, 'new_owner' => $newCrew]],
            $uAct,
            '2026-07-03',
        );
        $this->assertSame([], $this->events('region_lost'));
        $taken = $this->events('region_taken');
        $this->assertCount(1, $taken);
        $this->assertSame($uCol, (int)$taken[0]['user_id']);
        $this->assertSame(1, $written);
    }
}
