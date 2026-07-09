<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameConfig;
use App\Game\RegionImportService;
use App\Game\RegionOwnershipService;
use App\Game\RegionRepository;
use Tests\IntegrationTestCase;

/**
 * regions:recorrect — Korrektur grenzüberschreitend falsch verknüpfter L4-Gebiete
 * (Center-PiP != Elternland) + Dedup. Prüft: klare Fälle werden ans echte Land
 * verschoben (inkl. Subtree-path/cc), politisch umstrittene (RU) bleiben, leere
 * Namensdubletten werden entfernt, und ein zweiter Lauf ist ein No-Op (idempotent).
 */
final class RegionRecorrectTest extends IntegrationTestCase
{
    private RegionRepository $repo;
    private RegionImportService $import;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new RegionRepository($this->pdo);
        $this->import = new RegionImportService($this->repo);
        $this->seed();
    }

    /** Drei Länder nebeneinander (A=DE, B=NL, C=RU) mit je einer Provinz. */
    private function seed(): void
    {
        $feat = static function (int $lvl, string $name, float $x0, float $y0, float $x1, float $y1, ?string $cc): string {
            $props = ['boundary' => 'administrative', 'admin_level' => (string)$lvl, 'name' => $name];
            if ($cc !== null) {
                $props['ISO3166-1:alpha2'] = $cc;
            }
            return json_encode([
                'type' => 'Feature',
                'properties' => $props,
                'geometry' => ['type' => 'Polygon', 'coordinates' => [[[$x0, $y0], [$x1, $y0], [$x1, $y1], [$x0, $y1], [$x0, $y0]]]],
            ], JSON_THROW_ON_ERROR);
        };
        $lines = [
            $feat(2, 'Aland',  0.0, 0.0, 10.0, 10.0, 'DE'),
            $feat(2, 'Bland', 10.0, 0.0, 20.0, 10.0, 'NL'),
            $feat(2, 'Cland', 20.0, 0.0, 30.0, 10.0, 'RU'),
            $feat(4, 'ProvA',  1.0, 1.0,  4.0,  4.0, null),   // in A
            $feat(4, 'ProvB', 12.0, 1.0, 15.0,  4.0, null),   // in B
            $feat(4, 'ProvC', 22.0, 1.0, 25.0,  4.0, null),   // in C
        ];
        $path = sys_get_temp_dir() . '/region_rc_' . bin2hex(random_bytes(4)) . '.geojsonseq';
        file_put_contents($path, implode("\n", $lines) . "\n");
        try {
            $this->import->importFromGeojsonSeq($path, [2, 4]);
        } finally {
            @unlink($path);
        }
    }

    private function idByName(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM game_region WHERE name = ? ORDER BY id LIMIT 1');
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn();
    }

    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT parent_id, path, country_code FROM game_region WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    public function testReparentDedupAndDisputedSkip(): void
    {
        $aId = $this->idByName('Aland');
        $bId = $this->idByName('Bland');
        $provB = $this->idByName('ProvB');
        $provC = $this->idByName('ProvC');

        // Bug simulieren: ProvB und ProvC fälschlich unter A (cc DE geerbt).
        $this->repo->reparentSubtree($provB, $aId, $this->repo->pathOf($provB), "/$aId/$provB/", 'DE');
        $this->repo->reparentSubtree($provC, $aId, $this->repo->pathOf($provC), "/$aId/$provC/", 'DE');

        // Leere Namensdublette unter A (wie „Hamburg 2x").
        $dupeGeo = json_encode(['type' => 'Polygon', 'coordinates' => [[[1, 1], [4, 1], [4, 4], [1, 4], [1, 1]]]]);
        $this->pdo->prepare(
            "INSERT INTO game_region (level, kind, name, country_code, parent_id, path, center_lat, center_lon, min_lat, min_lon, max_lat, max_lon, boundary_geojson, bbox_geom)
             VALUES (4,'boundary','ProvA','DE',?, '/', 2.5,2.5,1,1,4,4, ?, ST_SRID(ST_GeomFromText('POLYGON((1 1,4 1,4 4,1 4,1 1))'),0))"
        )->execute([$aId, $dupeGeo]);
        $dupeId = (int)$this->pdo->lastInsertId();
        // path korrekt setzen (LAST_INSERT_ID im INSERT ist 0) — eindeutig unter A.
        $this->pdo->prepare('UPDATE game_region SET path = ? WHERE id = ?')->execute(["/$aId/$dupeId/", $dupeId]);

        $res = $this->import->recorrectMisparented(true);

        // ProvB → echtes Land B, path/cc mitgezogen.
        $rb = $this->row($provB);
        $this->assertSame($bId, (int)$rb['parent_id'], 'ProvB muss nach Bland verschoben sein');
        $this->assertSame("/$bId/$provB/", $rb['path']);
        $this->assertSame('NL', $rb['country_code']);

        // ProvC (echtes Land RU) bleibt unter A.
        $this->assertSame($aId, (int)$this->row($provC)['parent_id'], 'ProvC (RU) bleibt unangetastet');
        $skippedIds = array_column($res['skipped'], 'id');
        $this->assertContains($provC, $skippedIds);

        // Dublette entfernt, Original bleibt.
        $this->assertSame([], $this->row($dupeId), 'leere Dublette gelöscht');
        $this->assertNotSame([], $this->row($this->idByName('ProvA')), 'Original ProvA bleibt');
        $this->assertNotSame([], $res['dedup']);

        // Idempotenz: zweiter Lauf findet nichts mehr.
        $res2 = $this->import->recorrectMisparented(true);
        $this->assertSame([], $res2['reparented'], 'zweiter Lauf: keine Re-Parents');
        $this->assertSame([], $res2['dedup'], 'zweiter Lauf: keine Dedups');
    }
}
