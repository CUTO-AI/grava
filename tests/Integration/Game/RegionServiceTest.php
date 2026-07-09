<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameConfig;
use App\Game\GameRepository;
use App\Game\RegionImportService;
use App\Game\RegionOwnershipService;
use App\Game\RegionRepository;
use App\Game\RegionService;
use Tests\IntegrationTestCase;

/**
 * Gebiets-Eroberung Phase B, Lesepfad (CityConquest_Backend_Spec.md): Serialisierung
 * von /game/regions (zoom-adaptiv), /game/regions/{id} (Breadcrumb/Kinder/Bestenliste)
 * und /game/me/regions über RegionService.
 */
final class RegionServiceTest extends IntegrationTestCase
{
    private RegionRepository $repo;
    private RegionImportService $import;
    private RegionOwnershipService $own;
    private RegionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new RegionRepository($this->pdo);
        $this->import = new RegionImportService($this->repo);
        $config = new GameConfig($this->pdo);
        $this->own = new RegionOwnershipService($this->repo, $config);
        $this->svc = new RegionService($this->repo, new GameRepository($this->pdo), $config);
        $this->seed();
    }

    private function seed(): void
    {
        $feat = static fn(int $lvl, string $name, float $a, float $b, float $c, float $d): string => json_encode([
            'type' => 'Feature',
            'properties' => ['boundary' => 'administrative', 'admin_level' => (string)$lvl, 'name' => $name],
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$a, $b], [$c, $b], [$c, $d], [$a, $d], [$a, $b]]]],
        ], JSON_THROW_ON_ERROR);
        $lines = [
            $feat(2, 'Testland',  10.0, 47.0, 13.0, 49.0),
            $feat(4, 'Teststaat', 11.0, 47.5, 12.5, 48.5),
            $feat(6, 'Testkreis', 11.2, 47.7, 12.0, 48.2),
            $feat(8, 'Teststadt', 11.4, 47.8, 11.7, 48.0),
        ];
        $path = sys_get_temp_dir() . '/region_svc_' . bin2hex(random_bytes(4)) . '.geojsonseq';
        file_put_contents($path, implode("\n", $lines) . "\n");
        try {
            $this->import->importFromGeojsonSeq($path, [2, 4, 6, 8]);
        } finally {
            @unlink($path);
        }
    }

    private function regionId(int $level): int
    {
        return (int)$this->pdo->query("SELECT id FROM game_region WHERE level = $level")->fetchColumn();
    }

    private function claimant(string $handle): int
    {
        $uid = $this->createUser($handle);
        $this->pdo->prepare("INSERT INTO game_claimant (type, user_id) VALUES ('rider', ?)")->execute([$uid]);
        return (int)$this->pdo->lastInsertId();
    }

    private function ownedEdge(int $regionId, int $owner, float $len): void
    {
        static $seq = 0;
        $seq++;
        $this->pdo->prepare('INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, ?, ?)')->execute([9000 + $seq, 47.90, 11.55]);
        $na = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, ?, ?)')->execute([9500 + $seq, 47.91, 11.56]);
        $nb = (int)$this->pdo->lastInsertId();
        $geo = json_encode(['type' => 'LineString', 'coordinates' => [[11.55, 47.90], [11.56, 47.91]]]);
        $this->pdo->prepare(
            'INSERT INTO game_edge (way_id, node_a_id, node_b_id, length_m, geom_geojson, min_lat, min_lon, max_lat, max_lon, owner_claimant_id, region_id)
             VALUES (?, ?, ?, ?, ?, 47.90, 11.55, 47.91, 11.56, ?, ?)'
        )->execute([800 + $seq, $na, $nb, $len, $geo, $owner, $regionId]);
    }

    public function testLevelForSpanPicksZoomLevel(): void
    {
        $this->assertSame(2, $this->svc->levelForSpan(8.0));
        $this->assertSame(4, $this->svc->levelForSpan(2.0));
        $this->assertSame(6, $this->svc->levelForSpan(0.6));
        $this->assertSame(8, $this->svc->levelForSpan(0.1));
    }

    public function testRegionsInBboxSerializesOwner(): void
    {
        $muni = $this->regionId(8);
        $a = $this->claimant('alice');
        for ($i = 0; $i < 4; $i++) {
            $this->ownedEdge($muni, $a, 100.0);
        }
        $this->own->recomputeAll('2026-07-03 12:00:00.000');

        // bbox um die Gemeinde, Ebene 8 erzwungen.
        $res = $this->svc->regionsInBbox(11.3, 47.75, 11.8, 48.05, 8, false, $a);
        $this->assertSame(8, $res['level']);
        $found = null;
        foreach ($res['regions'] as $r) {
            if ($r['id'] === $muni) {
                $found = $r;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('Teststadt', $found['name']);
        $this->assertFalse($found['contested']);
        $this->assertTrue($found['mine']);
        $this->assertSame($a, $found['owner']['claimant_id']);
    }

    public function testRegionDetailHasBreadcrumbChildrenAndLeaderboard(): void
    {
        $county = $this->regionId(6);
        $muni = $this->regionId(8);
        $a = $this->claimant('bob');
        for ($i = 0; $i < 4; $i++) {
            $this->ownedEdge($muni, $a, 100.0);
        }
        $this->own->recomputeAll('2026-07-03 12:00:00.000');

        $detail = $this->svc->regionDetail($county, $a);
        $this->assertNotNull($detail);
        $this->assertSame('Testkreis', $detail['name']);
        // Breadcrumb hoch: Land + Bundesland (aufsteigend nach Ebene).
        $this->assertSame(['Testland', 'Teststaat'], array_column($detail['breadcrumb'], 'name'));
        // Kinder runter: die Gemeinde.
        $this->assertContains('Teststadt', array_column($detail['children'], 'name'));
        // Kinder tragen total_edges (Regression: childrenOf ohne o.total_edges → 0).
        $child = null;
        foreach ($detail['children'] as $c) {
            if ($c['name'] === 'Teststadt') {
                $child = $c;
            }
        }
        $this->assertNotNull($child);
        $this->assertSame(4, $child['total_edges']);
        // Bestenliste (Rollup): A führt mit 4 Kanten / 400 m.
        $this->assertSame($a, $detail['leaderboard'][0]['claimant_id']);
        $this->assertSame(4, $detail['leaderboard'][0]['held_edges']);
        $this->assertNotNull($detail['me']);
        $this->assertSame(1, $detail['me']['rank']);
    }

    public function testRootRegionsReturnsCountriesOnly(): void
    {
        $muni = $this->regionId(8);
        $a = $this->claimant('dave');
        for ($i = 0; $i < 4; $i++) {
            $this->ownedEdge($muni, $a, 100.0);
        }
        $this->own->recomputeAll('2026-07-03 12:00:00.000');

        $roots = $this->svc->rootRegions($a);
        $names = array_column($roots['regions'], 'name');
        // Nur die Wurzel (Land), keine Unter-Gebiete.
        $this->assertContains('Testland', $names);
        $this->assertNotContains('Teststadt', $names);

        $land = null;
        foreach ($roots['regions'] as $r) {
            if ($r['name'] === 'Testland') {
                $land = $r;
            }
        }
        $this->assertNotNull($land);
        $this->assertSame(2, $land['level']);
        $this->assertNull($land['parent_id']);
    }

    public function testSelfHealComputesOwnershipOnRead(): void
    {
        $muni = $this->regionId(8);
        $a = $this->claimant('erin');
        for ($i = 0; $i < 4; $i++) {
            $this->ownedEdge($muni, $a, 100.0);
        }
        // Besitz-Cache bewusst NICHT rechnen — leer.
        $this->assertSame(0, $this->repo->ownershipRowCount());

        // RegionService MIT Ownership-Service → Self-Heal beim Lesen.
        $svc = new RegionService($this->repo, new GameRepository($this->pdo), new GameConfig($this->pdo), $this->own);
        $res = $svc->regionsInBbox(11.3, 47.75, 11.8, 48.05, 8, false, $a);

        $this->assertGreaterThan(0, $this->repo->ownershipRowCount(), 'Self-Heal hat den Besitz gerechnet');
        $found = null;
        foreach ($res['regions'] as $r) { if ($r['id'] === $muni) { $found = $r; } }
        $this->assertNotNull($found);
        $this->assertFalse($found['contested']);
        $this->assertSame($a, $found['owner']['claimant_id']);
    }

    public function testMyRegionsListsOwnedAndContesting(): void
    {
        $muni = $this->regionId(8);
        $county = $this->regionId(6);
        $a = $this->claimant('carol');
        for ($i = 0; $i < 4; $i++) {
            $this->ownedEdge($muni, $a, 100.0);
        }
        $this->own->recomputeAll('2026-07-03 12:00:00.000');

        $mine = $this->svc->myRegions($a, null);
        $byId = [];
        foreach ($mine['regions'] as $r) {
            $byId[$r['id']] = $r;
        }
        // Gemeinde: besessen. Landkreis: nur geführt (umkämpft) → contesting.
        $this->assertTrue($byId[$muni]['owned']);
        $this->assertArrayHasKey($county, $byId);
        $this->assertTrue($byId[$county]['contesting']);
        $this->assertFalse($byId[$county]['owned']);
    }
}
