<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\RegionImportService;
use App\Game\RegionRepository;
use Tests\IntegrationTestCase;

/**
 * Rezidiv-Schutz beim Import (WebAnalytics_Concept / OPEN_ITEMS §H): linkHierarchy
 * darf ein Gebiet NICHT grenzüberschreitend an ein Nachbarland hängen, nur weil
 * dessen Bounding-Box den Punkt überlappt. Länder-Elter = strikter Punkt-in-
 * Polygon; bbox-Fallback nur bei Eindeutigkeit.
 */
final class RegionImportGuardTest extends IntegrationTestCase
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

    /**
     * Zwei Länder mit ÜBERLAPPENDER bbox (je ein dünner Arm über die volle Breite),
     * deren Polygone sich aber nicht überdecken:
     *  - A (DE): volle Höhe x0..4  + dünner Arm y0..0.5 über x0..20
     *  - B (NL): volle Höhe x16..20 + dünner Arm y3.5..4 über x0..20
     * Beide bbox = 0..20 × 0..4.
     */
    private function seed(): void
    {
        $feat = static function (int $lvl, string $name, array $ring, ?string $cc): string {
            $props = ['boundary' => 'administrative', 'admin_level' => (string)$lvl, 'name' => $name];
            if ($cc !== null) {
                $props['ISO3166-1:alpha2'] = $cc;
            }
            return json_encode([
                'type' => 'Feature',
                'properties' => $props,
                'geometry' => ['type' => 'Polygon', 'coordinates' => [$ring]],
            ], JSON_THROW_ON_ERROR);
        };
        $box = static fn(float $x0, float $y0, float $x1, float $y1): array =>
            [[$x0, $y0], [$x1, $y0], [$x1, $y1], [$x0, $y1], [$x0, $y0]];

        $A = [[0, 0], [20, 0], [20, 0.5], [4, 0.5], [4, 4], [0, 4], [0, 0]];        // L: x0..4 voll + Arm unten
        $B = [[16, 0], [20, 0], [20, 4], [0, 4], [0, 3.5], [16, 3.5], [16, 0]];     // x16..20 voll + Arm oben

        $lines = [
            $feat(2, 'Aland', $A, 'DE'),
            $feat(2, 'Bland', $B, 'NL'),
            $feat(4, 'ProvInB', $box(17.5, 1.5, 18.5, 2.5), null),  // Center ~ (18,2): nur in B
            $feat(4, 'ProvGap', $box(9.5, 1.5, 10.5, 2.5), null),   // Center ~ (10,2): in keinem Polygon, in beiden bboxes
        ];
        $path = sys_get_temp_dir() . '/region_guard_' . bin2hex(random_bytes(4)) . '.geojsonseq';
        file_put_contents($path, implode("\n", $lines) . "\n");
        try {
            $this->import->importFromGeojsonSeq($path, [2, 4]);
        } finally {
            @unlink($path);
        }
    }

    private function idByName(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM game_region WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        return (int)$stmt->fetchColumn();
    }

    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT parent_id, country_code FROM game_region WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    public function testProvinceGoesToContainingCountryNotBboxNeighbor(): void
    {
        $bId = $this->idByName('Bland');
        $provInB = $this->row($this->idByName('ProvInB'));

        // Trotz überlappender A-bbox: ProvInB hängt am geometrisch enthaltenden B,
        // und erbt dessen country_code (autoritativ).
        $this->assertSame($bId, (int)$provInB['parent_id']);
        $this->assertSame('NL', $provInB['country_code']);
    }

    public function testAmbiguousBorderPointIsNotGuessed(): void
    {
        $provGap = $this->row($this->idByName('ProvGap'));
        // Punkt in keinem Länderpolygon, aber in zwei Länder-bboxes → NICHT raten.
        $this->assertNull($provGap['parent_id'], 'Grenzlücke darf nicht ins falsche Land geraten werden');
    }
}
