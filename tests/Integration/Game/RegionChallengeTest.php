<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\Challenges\ChallengeService;
use App\Game\RegionRepository;
use Tests\IntegrationTestCase;

/**
 * Gebiets-Challenges (CityConquest_Backend_Spec.md, Phase D): „Halte eine
 * Gemeinde/einen Landkreis" — Fortschritt = aktueller Besitz-Snapshot des
 * effektiven Claimants.
 */
final class RegionChallengeTest extends IntegrationTestCase
{
    private ChallengeService $svc;
    private RegionRepository $regions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ChallengeService($this->pdo);
        $this->regions = new RegionRepository($this->pdo);
    }

    private function riderClaimant(int $userId): int
    {
        $this->pdo->prepare("INSERT INTO game_claimant (type, user_id) VALUES ('rider', ?)")->execute([$userId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function region(int $level, string $kind, string $name): int
    {
        return $this->regions->insertRegion([
            'osm_relation_id' => null, 'level' => $level, 'kind' => $kind, 'name' => $name,
            'country_code' => 'DE', 'center_lat' => 48.0, 'center_lon' => 12.0,
            'min_lat' => 47.9, 'min_lon' => 11.9, 'max_lat' => 48.1, 'max_lon' => 12.1,
            'area_km2' => 10.0,
            'boundary_geojson' => json_encode(['type' => 'Polygon', 'coordinates' => [[[11.9, 47.9], [12.1, 47.9], [12.1, 48.1], [11.9, 48.1], [11.9, 47.9]]]]),
        ]);
    }

    private function own(int $regionId, int $claimantId, int $contested = 0): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_region_ownership (region_id, owner_claimant_id, contested) VALUES (?, ?, ?)'
        )->execute([$regionId, $claimantId, $contested]);
    }

    /** @return array<string,array<string,mixed>> */
    private function byId(array $resp): array
    {
        $out = [];
        foreach ($resp['challenges'] as $c) {
            $out[(string)$c['id']] = $c;
        }
        return $out;
    }

    public function testHoldingMunicipalityCompletesChallenge(): void
    {
        $uid = $this->createUser('holder');
        $claimant = $this->riderClaimant($uid);
        $muni = $this->region(8, 'municipality', 'Kolbermoor');
        $this->own($muni, $claimant);

        $resp = $this->svc->forUser($uid, 'de');
        $byId = $this->byId($resp);

        $this->assertArrayHasKey('weekly_hold_municipality', $byId);
        $this->assertSame(1, $byId['weekly_hold_municipality']['progress']);
        $this->assertSame(1, $byId['weekly_hold_municipality']['target']);
        // Landkreis noch nicht gehalten.
        $this->assertSame(0, $byId['weekly_hold_district']['progress']);
        // Erfüllte Gemeinde-Challenge zählt in points_total (40) und wird festgehalten.
        $this->assertGreaterThanOrEqual(40, $resp['points_total']);
        $done = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM game_challenge_completion WHERE challenge_id='weekly_hold_municipality'"
        )->fetchColumn();
        $this->assertSame(1, $done);
    }

    public function testContestedRegionDoesNotCount(): void
    {
        $uid = $this->createUser('rider2');
        $claimant = $this->riderClaimant($uid);
        $muni = $this->region(8, 'municipality', 'Umkämpft');
        $this->own($muni, $claimant, contested: 1);

        $byId = $this->byId($this->svc->forUser($uid, 'en'));
        $this->assertSame(0, $byId['weekly_hold_municipality']['progress']);
        $this->assertSame('Hold a municipality', $byId['weekly_hold_municipality']['title']);
    }
}
