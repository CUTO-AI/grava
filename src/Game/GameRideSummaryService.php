<?php
declare(strict_types=1);

namespace App\Game;

use App\Game\Rush\RushRepository;
use App\Privacy\PrivacyZone;
use App\Privacy\PrivacyZoneRepository;
use App\Privacy\RoutePrivacyTrimmer;

/**
 * Per-Ride Eroberungs-Zusammenfassung (STRAVA_SHARE_BACKEND.md §2).
 * Read-only, idempotent — reine Ableitung aus game_edge_pass + Besitz.
 */
final class GameRideSummaryService
{
    /** Deckel gegen zu große Payload (viele berührte Gemeinden/Landkreise). */
    private const SHARE_REGION_LIMIT = 60;

    public function __construct(
        private readonly GameRepository $repo,
        private readonly RushRepository $rushes,
        private readonly PrivacyZoneRepository $privacyZones,
        private readonly RoutePrivacyTrimmer $trimmer,
        private readonly RegionRepository $regions,
    ) {}

    /**
     * @return array<string,mixed>|null null = Route unbekannt
     * @throws RideSummaryNotIngestedException wenn noch keine Pässe
     */
    public function summary(int $userId, string $routePublicId): ?array
    {
        $route = $this->repo->resolveRouteForIngest($routePublicId);
        if ($route === null || $route['user_id'] !== $userId) {
            return null;
        }

        $routeId = (int)$route['route_id'];
        $claimantId = $this->repo->effectiveClaimantId($userId);
        $stats = $this->repo->rideSummaryStats($routeId, $userId, $claimantId);

        if ($stats['edges_total'] === 0) {
            throw new RideSummaryNotIngestedException();
        }

        $zone = $this->privacyZone($userId);

        $regions = $this->regionBlocks($routeId, $userId, $claimantId);
        // „Neue Reviere" = in dieser Fahrt neu gewonnene Gemeinden (Ebene 8), damit
        // sich L6/L8 nicht doppeln. Fläche als Summe ihrer km² → m².
        $gainedMunis = array_filter(
            $regions,
            static fn(array $r): bool => $r['status'] === 'gained' && $r['level'] === 8
        );
        $areaSqm = 0.0;
        foreach ($gainedMunis as $r) {
            $areaSqm += ($r['area_km2'] ?? 0) * 1_000_000;
        }

        return [
            'edges_total'        => $stats['edges_total'],
            'edges_new'          => $stats['edges_new'],
            'edges_taken_over'   => $stats['edges_taken_over'],
            'pioneer_names'      => $stats['pioneer_names'],
            'territories_new'    => count($gainedMunis),
            'territory_area_sqm' => $areaSqm,
            'points_awarded'     => null,
            'rank_after'         => null,
            'rush'               => $this->rushBlock($routeId, $userId),
            'edges'              => $this->edgeBlocks($routeId, $userId, $claimantId, $zone),
            'regions'            => array_map(
                static fn(array $r): array => [
                    'name'   => $r['name'],
                    'level'  => $r['level'],
                    'kind'   => $r['kind'],
                    'status' => $r['status'],
                    'geom'   => $r['geom'],
                ],
                $regions
            ),
        ];
    }

    /**
     * Vom Ride berührte Verwaltungsgebiete (Landkreis L6 + Gemeinde/Stadt L8) mit
     * Besitzstatus und Grenzpolygon für die Share-Gebiets-Karte. Öffentliche
     * Verwaltungsgrenzen → keine Heimatzonen-Maskierung nötig. `status`:
     * `gained` (heute erobert) · `held` (eigenes) · `enemy` (fremd) · `neutral` (umkämpft).
     *
     * @return list<array{name:string,level:int,kind:string,status:string,area_km2:?float,geom:array<string,mixed>}>
     */
    private function regionBlocks(int $routeId, int $userId, int $claimantId): array
    {
        $leafIds = $this->repo->rideTouchedRegionIds($routeId, $userId);
        if ($leafIds === []) {
            return [];
        }

        // Blatt-Meta → Ahnenkette (aus dem materialisierten path) einsammeln.
        $candidate = [];
        foreach ($leafIds as $id) {
            $candidate[$id] = true;
        }
        foreach ($this->regions->shareMetaForRegions($leafIds) as $m) {
            foreach (explode('/', trim($m['path'], '/')) as $anc) {
                if ($anc !== '') {
                    $candidate[(int)$anc] = true;
                }
            }
        }

        // Nur Landkreis (6) + Gemeinde/Stadt (8); L8 zuerst (Karten-Füllung),
        // dann L6 (Umriss). Deckel gegen zu große Payload.
        $meta = $this->regions->shareMetaForRegions(array_keys($candidate));
        $keep = array_filter($meta, static fn(array $m): bool => in_array($m['level'], [6, 8], true));
        if ($keep === []) {
            return [];
        }
        uasort($keep, static fn(array $a, array $b): int => $b['level'] <=> $a['level']);

        $owners = $this->regions->currentOwnersFor(array_keys($keep));
        $today = \App\Support\Clock::nowUtc()->format('Y-m-d');

        $out = [];
        foreach ($keep as $id => $m) {
            if (count($out) >= self::SHARE_REGION_LIMIT) {
                break;
            }
            $raw = $this->regions->boundaryGeojson($id);
            if ($raw === null) {
                continue;
            }
            $geom = json_decode($raw, true);
            if (!is_array($geom)) {
                continue;
            }
            $out[] = [
                'name'     => $m['name'],
                'level'    => $m['level'],
                'kind'     => $m['kind'],
                'status'   => $this->regionStatus($owners[$id] ?? null, $claimantId, $today),
                'area_km2' => $m['area_km2'],
                'geom'     => $geom,
            ];
        }
        return $out;
    }

    /**
     * Besitzstatus eines Gebiets aus Sicht des Fahrers.
     *
     * @param array{owner:?int,since:?string}|null $owner
     */
    private function regionStatus(?array $owner, int $claimantId, string $today): string
    {
        if ($owner === null || $owner['owner'] === null) {
            return 'neutral';
        }
        if ($owner['owner'] !== $claimantId) {
            return 'enemy';
        }
        // Eigenes Gebiet: „gained", wenn der Besitz heute übergegangen ist.
        if ($owner['since'] !== null && substr($owner['since'], 0, 10) === $today) {
            return 'gained';
        }
        return 'held';
    }

    /** @return list<array{category:string,geom:array<string,mixed>}> */
    private function edgeBlocks(int $routeId, int $userId, int $claimantId, ?PrivacyZone $zone): array
    {
        $out = [];
        foreach ($this->repo->rideSummaryEdges($routeId, $userId, $claimantId) as $row) {
            $geom = $this->maskedGeom($row['geom_geojson'], $zone);
            if ($geom === null) {
                continue;
            }
            $out[] = [
                'category' => $row['category'],
                'geom'     => $geom,
            ];
        }
        return $out;
    }

    private function privacyZone(int $userId): ?PrivacyZone
    {
        $row = $this->privacyZones->find($userId);
        if ($row === null || !$row['enabled']) {
            return null;
        }
        return new PrivacyZone($row['lat'], $row['lon'], $row['radius_m']);
    }

    /**
     * @return array<string,mixed>|null GeoJSON LineString [lon,lat], privatzonen-getrimmt
     */
    private function maskedGeom(string $geomJson, ?PrivacyZone $zone): ?array
    {
        $geom = json_decode($geomJson, true);
        if (!is_array($geom) || ($geom['type'] ?? null) !== 'LineString'
            || !is_array($geom['coordinates'] ?? null)) {
            return null;
        }

        $fc = [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'properties' => new \stdClass(),
                'geometry' => $geom,
            ]],
        ];
        if ($zone !== null) {
            $fc = $this->trimmer->trim($fc, $zone);
        }

        $best = null;
        $bestLen = 0;
        foreach ($fc['features'] ?? [] as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $g = $feature['geometry'] ?? null;
            if (!is_array($g) || ($g['type'] ?? null) !== 'LineString') {
                continue;
            }
            $coords = $g['coordinates'] ?? [];
            if (!is_array($coords) || count($coords) < 2) {
                continue;
            }
            if (count($coords) > $bestLen) {
                $bestLen = count($coords);
                $best = $g;
            }
        }

        return $best;
    }

    /** @return array<string,mixed>|null */
    private function rushBlock(int $routeId, int $userId): ?array
    {
        $agg = $this->repo->rideRushAggregate($routeId, $userId);
        if ($agg === null) {
            return null;
        }
        $rush = $this->rushes->byId((int)$agg['rush_id']);
        if ($rush === null) {
            return null;
        }
        $crewName = $this->repo->crewNameById((int)$rush['crew_id']);

        return [
            'type'         => 'crew',
            'crew_name'    => $crewName ?? 'Crew',
            'multiplier'   => round((float)$rush['multiplier'], 1),
            'edges_rushed' => (int)$agg['edges_rushed'],
        ];
    }
}
