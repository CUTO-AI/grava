<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\RegionImportService;
use App\Game\RegionRepository;
use Tests\IntegrationTestCase;

/**
 * Prod-Sync der Gebiets-Eroberung (CityConquest_Backend_Spec.md): der lokal
 * berechnete game_region-Baum wird chunk-weise nach PROD gepusht
 * (/internal/regions/import). Kritisch ist der VERBATIM-Import inkl.
 * id/parent_id/path — sonst brächen die Hierarchie-Referenzen. Dieser Test
 * prüft den Export→Import-Rundlauf ohne HTTP.
 */
final class RegionProdSyncTest extends IntegrationTestCase
{
    private RegionRepository $repo;
    private RegionImportService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new RegionRepository($this->pdo);
        $this->svc = new RegionImportService($this->repo);

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
        $path = sys_get_temp_dir() . '/region_sync_' . bin2hex(random_bytes(4)) . '.geojsonseq';
        file_put_contents($path, implode("\n", $lines) . "\n");
        try {
            $this->svc->importFromGeojsonSeq($path, [2, 4, 6, 8]);
        } finally {
            @unlink($path);
        }
    }

    public function testExportImportRoundTripPreservesIdsAndHierarchy(): void
    {
        // Vorher-Zustand exportieren.
        $before = [];
        foreach ($this->repo->exportPage(0, 1000) as $r) {
            $before[(int)$r['id']] = ['parent_id' => $r['parent_id'], 'path' => $r['path'], 'name' => $r['name']];
        }
        $this->assertCount(4, $before);

        // Wie der Push: chunk-weise JSON bauen, verbatim re-importieren (replace).
        $rows = $this->repo->exportPage(0, 1000);
        $json = json_encode(['replace' => true, 'rows' => $rows], JSON_THROW_ON_ERROR);
        $res = $this->svc->importRowsJson($json);
        $this->assertSame(4, $res['received']);
        $this->assertSame(4, $res['imported']);
        $this->assertTrue($res['replace']);

        // Nachher: identische ids, parent_id, path.
        $after = [];
        foreach ($this->repo->exportPage(0, 1000) as $r) {
            $after[(int)$r['id']] = ['parent_id' => $r['parent_id'], 'path' => $r['path'], 'name' => $r['name']];
        }
        $this->assertSame(array_keys($before), array_keys($after), 'ids müssen erhalten bleiben');
        foreach ($before as $id => $b) {
            $this->assertSame($b['path'], $after[$id]['path'], "path von {$id}");
            $this->assertSame((string)$b['parent_id'], (string)$after[$id]['parent_id'], "parent_id von {$id}");
        }

        // Zweiter Chunk (replace=false) fügt additiv hinzu / aktualisiert idempotent.
        $res2 = $this->svc->importRowsJson(json_encode(['replace' => false, 'rows' => $rows], JSON_THROW_ON_ERROR));
        $this->assertSame(4, $res2['imported']);
        $this->assertSame(4, $this->repo->regionRowCount(), 'idempotent — keine Duplikate');
    }
}
