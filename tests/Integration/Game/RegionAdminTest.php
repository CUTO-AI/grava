<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\RegionRepository;
use Tests\IntegrationTestCase;

/**
 * Web-Admin-Gebietsübersicht (CityConquest, Phase D): adminRegionOverview()
 * fasst Besitz je Ebene, eroberte Gebiete und Top-Besitzer zusammen — mit
 * Besitzername (Crew-Name bzw. Rider-Handle).
 */
final class RegionAdminTest extends IntegrationTestCase
{
    private RegionRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new RegionRepository($this->pdo);
    }

    private function riderClaimant(string $handle): int
    {
        $uid = $this->createUser($handle);
        $this->pdo->prepare("INSERT INTO game_claimant (type, user_id) VALUES ('rider', ?)")->execute([$uid]);
        return (int)$this->pdo->lastInsertId();
    }

    private function region(int $level, string $kind, string $name): int
    {
        return $this->repo->insertRegion([
            'osm_relation_id' => null, 'level' => $level, 'kind' => $kind, 'name' => $name,
            'country_code' => 'DE', 'center_lat' => 48.0, 'center_lon' => 12.0,
            'min_lat' => 47.9, 'min_lon' => 11.9, 'max_lat' => 48.1, 'max_lon' => 12.1, 'area_km2' => 10.0,
            'boundary_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => [[[11.9, 47.9], [12.1, 47.9], [12.1, 48.1], [11.9, 48.1], [11.9, 47.9]]]]),
        ]);
    }

    private function own(int $regionId, int $claimantId, int $edges, float $frac, int $contested = 0): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_region_ownership (region_id, owner_claimant_id, total_edges, held_fraction, contested)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$regionId, $claimantId, $edges, $frac, $contested]);
    }

    public function testOverviewSummarizesOwnershipWithOwnerNames(): void
    {
        $alice = $this->riderClaimant('alice');
        $muni = $this->region(8, 'municipality', 'Kolbermoor');
        $muni2 = $this->region(8, 'municipality', 'Umkämpft');
        $district = $this->region(6, 'county', 'Landkreis Rosenheim');
        $this->own($muni, $alice, 52, 0.41);
        $this->own($district, $alice, 210, 0.6);
        $this->own($muni2, $alice, 1, 0.05, contested: 1); // umkämpft → zählt nicht als erobert

        $o = $this->repo->adminRegionOverview();

        // Summary je Ebene: L8 = 2 mit Kanten, 1 erobert; L6 = 1/1.
        $byLevel = [];
        foreach ($o['summary'] as $s) { $byLevel[$s['level']] = $s; }
        $this->assertSame(2, $byLevel[8]['with_edges']);
        $this->assertSame(1, $byLevel[8]['owned']);
        $this->assertSame(1, $byLevel[8]['contested']);
        $this->assertSame(1, $byLevel[6]['owned']);

        // Eroberte Gebiete (ohne umkämpfte): Kolbermoor + Landkreis, mit Besitzername.
        $names = array_column($o['owned'], 'name');
        $this->assertContains('Kolbermoor', $names);
        $this->assertContains('Landkreis Rosenheim', $names);
        $this->assertNotContains('Umkämpft', $names);
        $this->assertSame('alice', $o['owned'][0]['owner_name']);
        $this->assertSame('rider', $o['owned'][0]['owner_type']);

        // Top-Besitzer: alice mit 2 Gebieten (1 Gemeinde, 1 Landkreis).
        $this->assertCount(1, $o['topOwners']);
        $this->assertSame('alice', $o['topOwners'][0]['owner_name']);
        $this->assertSame(2, $o['topOwners'][0]['regions']);
        $this->assertSame(1, $o['topOwners'][0]['municipalities']);
        $this->assertSame(1, $o['topOwners'][0]['districts']);
    }
}
