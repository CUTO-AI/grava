<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameConfig;
use App\Game\RegionImportService;
use App\Game\RegionOwnershipService;
use App\Game\RegionRepository;
use PDO;
use Tests\IntegrationTestCase;

/**
 * Gebiets-Eroberung Phase B (CityConquest_Backend_Spec.md): Bottom-up-Besitz-
 * Rollup + Kontrollschwelle je Ebene, Cache game_region_ownership, Besitzwechsel.
 */
final class RegionOwnershipTest extends IntegrationTestCase
{
    private RegionRepository $repo;
    private RegionImportService $import;
    private RegionOwnershipService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new RegionRepository($this->pdo);
        $this->import = new RegionImportService($this->repo);
        $this->svc = new RegionOwnershipService($this->repo, new GameConfig($this->pdo));
        $this->seedNestedRegions();
    }

    private function seedNestedRegions(): void
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
        $path = sys_get_temp_dir() . '/region_own_' . bin2hex(random_bytes(4)) . '.geojsonseq';
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

    private function createClaimant(string $handle): int
    {
        $uid = $this->createUser($handle);
        $stmt = $this->pdo->prepare("INSERT INTO game_claimant (type, user_id) VALUES ('rider', ?)");
        $stmt->execute([$uid]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Legt eine eroberte Kante direkt im Gebiet an (region_id + owner gesetzt). */
    private function ownedEdge(int $regionId, ?int $ownerClaimantId, float $lengthM): void
    {
        static $seq = 0;
        $seq++;
        $na = $this->node(5_000 + $seq, 47.90, 11.55);
        $nb = $this->node(6_000 + $seq, 47.91, 11.56);
        $geo = json_encode(['type' => 'LineString', 'coordinates' => [[11.55, 47.90], [11.56, 47.91]]]);
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_edge
               (way_id, node_a_id, node_b_id, length_m, geom_geojson, min_lat, min_lon, max_lat, max_lon,
                owner_claimant_id, region_id)
             VALUES (?, ?, ?, ?, ?, 47.90, 11.55, 47.91, 11.56, ?, ?)'
        );
        $stmt->execute([700 + $seq, $na, $nb, $lengthM, $geo, $ownerClaimantId, $regionId]);
    }

    private function node(int $osmId, float $lat, float $lon): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, ?, ?)');
        $stmt->execute([$osmId, $lat, $lon]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function ownership(int $regionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_region_ownership WHERE region_id = ?');
        $stmt->execute([$regionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? [] : $row;
    }

    public function testOwnerTakesMunicipalityAboveThreshold(): void
    {
        $muni = $this->regionId(8);
        $county = $this->regionId(6);
        $country = $this->regionId(2);
        $a = $this->createClaimant('alice');

        // 4 eroberte Kanten (je 100 m) → Gemeinde: 100 % Anteil, 4 ≥ 3 Kanten.
        for ($i = 0; $i < 4; $i++) {
            $this->ownedEdge($muni, $a, 100.0);
        }

        $res = $this->svc->recomputeAll('2026-07-03 12:00:00.000');

        // Gemeinde (Ebene 8, ownable): A ist Eigentümer.
        $mo = $this->ownership($muni);
        $this->assertSame($a, (int)$mo['owner_claimant_id']);
        $this->assertSame(0, (int)$mo['contested']);
        $this->assertSame($a, (int)$mo['leader_claimant_id']);
        $this->assertEqualsWithDelta(1.0, (float)$mo['held_fraction'], 1e-9);

        // Landkreis (Ebene 6, ownable): Rollup 4 Kanten < 15 min_edges → umkämpft, aber A führt.
        $co = $this->ownership($county);
        $this->assertNull($co['owner_claimant_id']);
        $this->assertSame(1, (int)$co['contested']);
        $this->assertSame($a, (int)$co['leader_claimant_id']);
        $this->assertSame(4, (int)$co['total_edges']);

        // Land (Ebene 2, NICHT ownable): nie Eigentümer, aber Führung sichtbar.
        $countryRow = $this->ownership($country);
        $this->assertNull($countryRow['owner_claimant_id']);
        $this->assertSame($a, (int)$countryRow['leader_claimant_id']);

        // Besitzwechsel enthält die Gemeinde (null → A).
        $changed = array_filter($res['changes'], static fn($c) => $c['region_id'] === $muni);
        $this->assertCount(1, $changed);
    }

    public function testBelowEdgeThresholdStaysContested(): void
    {
        $muni = $this->regionId(8);
        $a = $this->createClaimant('bob');
        // Nur 2 eroberte Kanten (< min_edges 3) → umkämpft.
        $this->ownedEdge($muni, $a, 100.0);
        $this->ownedEdge($muni, $a, 100.0);

        $this->svc->recomputeAll('2026-07-03 12:00:00.000');

        $mo = $this->ownership($muni);
        $this->assertNull($mo['owner_claimant_id']);
        $this->assertSame(1, (int)$mo['contested']);
        $this->assertSame($a, (int)$mo['leader_claimant_id']);
    }

    public function testContestedWhenNoMajority(): void
    {
        $muni = $this->regionId(8);
        $a = $this->createClaimant('carol');
        $b = $this->createClaimant('dave');
        // A: 3×100 m, B: 3×100 m → je 50 %, aber A führt per Tie-Break (kleinere id).
        for ($i = 0; $i < 3; $i++) {
            $this->ownedEdge($muni, $a, 100.0);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->ownedEdge($muni, $b, 100.0);
        }

        $this->svc->recomputeAll('2026-07-03 12:00:00.000');

        $mo = $this->ownership($muni);
        // 50 % ≥ 0.25 und 3 ≥ 3 → A wird Eigentümer (deterministischer Führender).
        $this->assertSame($a, (int)$mo['owner_claimant_id']);
        $this->assertEqualsWithDelta(0.5, (float)$mo['held_fraction'], 1e-9);
    }
}
