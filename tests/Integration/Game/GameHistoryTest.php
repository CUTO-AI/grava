<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameHistoryService;
use App\Game\GameIngestionService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Routes\GeometryParser;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\IntegrationTestCase;

/**
 * Revier-Verlauf (GameHistory_Backend_Spec.md): Voll-Rebuild aus den Fahrdaten
 * (Held nach Fahrdatum, nicht owner_since), täglicher Snapshot (idempotent) und
 * der Lese-Pfad.
 */
final class GameHistoryTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameHistoryService $history;
    private int $claimant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $config = new GameConfig($this->pdo);
        $uid = $this->createUser('armin');

        // Eine Kante am 2026-06-20 erobern → owner_since + discovered_at = dieser Tag.
        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
        $route = (new GeometryParser())->parse('{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}');
        $segs = [new MatchedSegment(1001, 10, 11, 120.0, [[9.65, 47.12], [9.66, 47.13]], 'gravel', 18.0, 8.0, true, $now)];
        (new GameIngestionService(
            new FakeEdgeMatcher($segs), $this->repo,
            new EdgeRecalculator($this->repo, $config), $config, $this->pdo,
        ))->ingest(1, $uid, $route, true, $now);

        $this->history = new GameHistoryService($this->repo);
        $this->claimant = $this->repo->riderClaimantId($uid);
    }

    public function testSnapshotBackfillsAndReadsHistory(): void
    {
        $res = $this->history->snapshotAll('2026-06-25');
        $this->assertSame(1, $res['claimants']);
        $this->assertSame(1, $res['rebuilt']);

        // Weites Fenster (unabhängig von der realen Uhr), chronologisch.
        $points = $this->history->history($this->claimant, 100000)['points'];
        $byDate = [];
        foreach ($points as $p) {
            $byDate[$p['date']] = $p;
        }

        // Backfill-Punkt am Erwerbstag.
        $this->assertArrayHasKey('2026-06-20', $byDate);
        $this->assertSame(1, $byDate['2026-06-20']['held_edges']);
        $this->assertSame(1, $byDate['2026-06-20']['pioneered_edges']);
        $this->assertEqualsWithDelta(120.0, $byDate['2026-06-20']['held_length_m'], 0.01);

        // Exakter Tages-Snapshot.
        $this->assertArrayHasKey('2026-06-25', $byDate);
        $this->assertSame(1, $byDate['2026-06-25']['held_edges']);
        $this->assertEqualsWithDelta(120.0, $byDate['2026-06-25']['held_length_m'], 0.01);
    }

    public function testSnapshotIsIdempotent(): void
    {
        $this->history->snapshotAll('2026-06-25');
        $second = $this->history->snapshotAll('2026-06-25');

        // Voll-Rebuild ist idempotent: gleicher Eingang ⇒ gleiche Tabelle.
        $this->assertSame(1, $second['rebuilt']);

        // Keine Duplikate (Rebuild ersetzt atomar; UNIQUE claimant_id+snapshot_date).
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM game_user_stats_daily WHERE claimant_id = ? AND snapshot_date = ?'
        );
        $stmt->execute([$this->claimant, '2026-06-25']);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testEnsureTodaySnapshotBackfillsAndWritesToday(): void
    {
        // Ohne Cron: erster Lese-Zugriff backfillt + schreibt den heutigen Punkt.
        $this->assertFalse($this->repo->hasDailySnapshots($this->claimant));
        $this->history->ensureTodaySnapshot($this->claimant);
        $this->assertTrue($this->repo->hasDailySnapshots($this->claimant));

        $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $stmt = $this->pdo->prepare(
            'SELECT held_edges FROM game_user_stats_daily WHERE claimant_id = ? AND snapshot_date = ?'
        );
        $stmt->execute([$this->claimant, $today]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testHeldDatedByRideDateNotOwnerSince(): void
    {
        // Import-Szenario: owner_since wird beim Recompute mit der Verarbeitungszeit
        // gestempelt. Simuliere einen Batch-Import, der die Kante (gefahren am
        // 2026-06-20) erst am 2026-07-10 verarbeitet — owner_since = Import-Tag,
        // die Vorbeifahrt (ridden_at) bleibt beim echten Fahrdatum.
        $this->pdo->prepare(
            'UPDATE game_edge SET owner_since = ? WHERE owner_claimant_id = ?'
        )->execute(['2026-07-10 12:00:00.000', $this->claimant]);

        $this->history->snapshotAll('2026-07-15');
        $byDate = [];
        foreach ($this->history->history($this->claimant, 100000)['points'] as $p) {
            $byDate[$p['date']] = $p;
        }

        // Der Zuwachs liegt auf dem FAHRDATUM, nicht auf dem Import-Tag …
        $this->assertArrayHasKey('2026-06-20', $byDate);
        $this->assertSame(1, $byDate['2026-06-20']['held_edges']);
        // … und es gibt keine Stufe am Import-/Verarbeitungstag.
        $this->assertArrayNotHasKey('2026-07-10', $byDate);
    }

    public function testCrewOwnedEdgeDatedByMemberRideDate(): void
    {
        // Crew-Fall: Besitz läuft über den Group-Claimant, die Pässe behalten aber
        // die Rider-Claimant-ID. Ohne Auflösung über die Mitgliedschaft fände die
        // Datierung keine Pässe und fiele auf owner_since (Import-Tag) zurück.
        $uid = $this->createUser('crewrider');
        $now = new DateTimeImmutable('2026-05-01T08:00:00Z', new DateTimeZone('UTC'));
        $route = (new GeometryParser())->parse('{"type":"LineString","coordinates":[[9.70,47.20],[9.71,47.21]]}');
        $segs = [new MatchedSegment(2002, 20, 21, 90.0, [[9.70, 47.20], [9.71, 47.21]], 'gravel', 18.0, 8.0, true, $now)];
        $config = new GameConfig($this->pdo);
        (new GameIngestionService(
            new FakeEdgeMatcher($segs), $this->repo,
            new EdgeRecalculator($this->repo, $config), $config, $this->pdo,
        ))->ingest(2, $uid, $route, true, $now);

        // Crew anlegen und die eben eroberte Kante dem Group-Claimant zuschlagen,
        // owner_since = späterer „Import-Tag".
        $this->pdo->exec("INSERT INTO game_claimant (type, user_id) VALUES ('group', NULL)");
        $groupId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$groupId, 'Testcrew', 'testcrew', $uid, 'JOIN1234']);
        $crewId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO game_crew_member (user_id, crew_id, role) VALUES (?, ?, 'captain')")
            ->execute([$uid, $crewId]);
        $this->pdo->prepare(
            'UPDATE game_edge SET owner_claimant_id = ?, owner_since = ? WHERE way_id = 2002'
        )->execute([$groupId, '2026-07-11 12:00:00.000']);

        $this->history->rebuild($groupId, '2026-07-15');
        $byDate = [];
        foreach ($this->history->history($groupId, 100000)['points'] as $p) {
            $byDate[$p['date']] = $p;
        }

        // Datiert auf das FAHRDATUM des Crew-Mitglieds, nicht den Import-Tag.
        $this->assertArrayHasKey('2026-05-01', $byDate);
        $this->assertSame(1, $byDate['2026-05-01']['held_edges']);
        $this->assertArrayNotHasKey('2026-07-11', $byDate);
    }

    public function testHistoryWindowExcludesOlderPoints(): void
    {
        $this->history->snapshotAll('2026-06-25');
        // Sehr kurzes Fenster ab heute (realer Uhr): die Juni-2026-Punkte liegen weit
        // vor „heute minus 1 Tag" und dürfen daher nicht erscheinen.
        $points = $this->history->history($this->claimant, 1)['points'];
        $this->assertSame([], $points);
    }
}
