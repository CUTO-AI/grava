<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\RegionImportService;
use App\Game\RegionRepository;
use PDO;
use Tests\IntegrationTestCase;

/**
 * Gebiets-Eroberung Phase A (CityConquest_Backend_Spec.md): Grenzen-Import in die
 * game_region-Hierarchie (parent_id/path per Center-Point-in-Polygon) und der
 * Edge→Gebiet-Backfill (game_edge.region_id = feinstes enthaltendes Gebiet).
 */
final class RegionImportTest extends IntegrationTestCase
{
    private RegionRepository $repo;
    private RegionImportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new RegionRepository($this->pdo);
        $this->svc = new RegionImportService($this->repo);
    }

    /** Vier verschachtelte Rechteck-Gebiete: Land ⊃ Bundesland ⊃ Landkreis ⊃ Gemeinde. */
    private function writeNestedSeq(): string
    {
        $feat = static function (int $lvl, string $name, float $minLon, float $minLat, float $maxLon, float $maxLat): string {
            return json_encode([
                'type' => 'Feature',
                'properties' => ['boundary' => 'administrative', 'admin_level' => (string)$lvl, 'name' => $name],
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[
                    [$minLon, $minLat], [$maxLon, $minLat], [$maxLon, $maxLat], [$minLon, $maxLat], [$minLon, $minLat],
                ]]],
            ], JSON_THROW_ON_ERROR);
        };
        $lines = [
            $feat(2, 'Testland',  10.0, 47.0, 13.0, 49.0),
            $feat(4, 'Teststaat', 11.0, 47.5, 12.5, 48.5),
            $feat(6, 'Testkreis', 11.2, 47.7, 12.0, 48.2),
            $feat(8, 'Teststadt', 11.4, 47.8, 11.7, 48.0),
        ];
        $path = sys_get_temp_dir() . '/region_nested_' . bin2hex(random_bytes(4)) . '.geojsonseq';
        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    public function testImportBuildsHierarchy(): void
    {
        $path = $this->writeNestedSeq();
        try {
            $res = $this->svc->importFromGeojsonSeq($path, [2, 4, 6, 8]);
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, $res['inserted'][2]);
        $this->assertSame(1, $res['inserted'][8]);
        $this->assertSame(4, $res['linked']);

        // Gemeinde hat den vollen Pfad (4 Ebenen) und den Landkreis als Elter.
        $muni = $this->pdo->query(
            "SELECT r.name, r.path, p.name AS pname, p.level AS plevel
               FROM game_region r LEFT JOIN game_region p ON p.id = r.parent_id
              WHERE r.level = 8"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Teststadt', $muni['name']);
        $this->assertSame('Testkreis', $muni['pname']);
        $this->assertSame(6, (int)$muni['plevel']);
        $this->assertSame(4, substr_count($muni['path'], '/') - 1); // '/a/b/c/d/' → 4 ids

        // Land ist Wurzel ohne Elter.
        $country = $this->pdo->query("SELECT parent_id FROM game_region WHERE level = 2")->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($country['parent_id']);
    }

    public function testBackfillAssignsEdgeToFinestRegion(): void
    {
        $path = $this->writeNestedSeq();
        try {
            $this->svc->importFromGeojsonSeq($path, [2, 4, 6, 8]);
        } finally {
            @unlink($path);
        }
        $muniId = (int)$this->pdo->query("SELECT id FROM game_region WHERE level = 8")->fetchColumn();

        // Eine Kante mit Mittelpunkt (11.55, 47.90) → in der Gemeinde.
        $inside = $this->insertEdge(11.54, 47.89, 11.56, 47.91);
        // Eine Kante weit außerhalb aller Gebiete.
        $outside = $this->insertEdge(-0.01, -0.01, 0.01, 0.01);

        $res = $this->svc->backfillEdges(true, 100);
        $this->assertSame(2, $res['scanned']);
        $this->assertSame(1, $res['assigned']);

        $this->assertSame($muniId, $this->edgeRegion($inside));
        $this->assertNull($this->edgeRegion($outside));
    }

    private function insertEdge(float $minLon, float $minLat, float $maxLon, float $maxLat): int
    {
        static $seq = 0;
        $seq++;
        $na = $this->insertNode(1_000 + $seq, $minLat, $minLon);
        $nb = $this->insertNode(2_000 + $seq, $maxLat, $maxLon);
        $geo = json_encode(['type' => 'LineString', 'coordinates' => [[$minLon, $minLat], [$maxLon, $maxLat]]]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_edge
               (way_id, node_a_id, node_b_id, length_m, geom_geojson, min_lat, min_lon, max_lat, max_lon)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([100 + $seq, $na, $nb, 120.0, $geo, $minLat, $minLon, $maxLat, $maxLon]);
        return (int)$this->pdo->lastInsertId();
    }

    private function insertNode(int $osmId, float $lat, float $lon): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, ?, ?)');
        $stmt->execute([$osmId, $lat, $lon]);
        return (int)$this->pdo->lastInsertId();
    }

    private function edgeRegion(int $edgeId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT region_id FROM game_edge WHERE id = ?');
        $stmt->execute([$edgeId]);
        $v = $stmt->fetchColumn();
        return $v === null || $v === false ? null : (int)$v;
    }
}
