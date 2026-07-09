<?php
declare(strict_types=1);

namespace App\Game;

/**
 * Lesepfad der Gebiets-Eroberung (CityConquest_Backend_Spec.md, Phase B):
 * serialisiert Gebiete + Besitz für die Endpunkte /game/regions,
 * /game/regions/{id} und /game/me/regions. Wählt bei fehlendem `level` die
 * zoom-passende Ebene aus der bbox-Spanne (Config region_level_span_breaks),
 * analog MapLod::adaptiveGrid. Besitzer/Führer werden über die bestehende
 * GameRepository::claimantInfo()-Kurzform ausgegeben (identisch zu /game/edges).
 */
final class RegionService
{
    /** @var array<int,array<string,mixed>|null> claimantInfo-Cache je Request. */
    private array $claimantCache = [];

    public function __construct(
        private readonly RegionRepository $repo,
        private readonly GameRepository $game,
        private readonly GameConfig $config,
        private readonly ?RegionOwnershipService $ownership = null,
    ) {}

    /**
     * Self-Heal: ist der Besitz-Cache leer (Cron nie gelaufen, noch kein Ingest),
     * einmal rechnen. Bei 0 Spielkanten ist recomputeAll ein No-Op → billig.
     */
    private function ensureOwnership(): void
    {
        if ($this->ownership !== null && $this->repo->ownershipRowCount() === 0) {
            $this->ownership->recomputeAll();
        }
    }

    /**
     * GET /game/regions — Gebiete im Ausschnitt (zoom-adaptiv).
     *
     * @return array{level:int,regions:list<array<string,mixed>>}
     */
    public function regionsInBbox(
        float $minLon,
        float $minLat,
        float $maxLon,
        float $maxLat,
        ?int $level,
        bool $withGeometry,
        ?int $viewerClaimant
    ): array {
        $this->ensureOwnership();
        if ($level === null) {
            $span = max($maxLat - $minLat, $maxLon - $minLon);
            $level = $this->levelForSpan($span);
        }
        $limit = max(1, $this->config->int('region_list_max'));
        $rows = $this->repo->regionsInBbox($level, $minLon, $minLat, $maxLon, $maxLat, $limit, $withGeometry);

        $regions = [];
        foreach ($rows as $r) {
            $regions[] = $this->serializeRegion($r, $viewerClaimant, $withGeometry);
        }
        return ['level' => $level, 'regions' => $regions];
    }

    /**
     * Wurzel-Gebiete (Länder) für den Einstieg in die Web-Gebietsliste.
     *
     * @return array{regions:list<array<string,mixed>>}
     */
    public function rootRegions(?int $viewerClaimant): array
    {
        $this->ensureOwnership();
        $regions = [];
        foreach ($this->repo->rootRegions() as $r) {
            $regions[] = $this->serializeRegion($r, $viewerClaimant, false);
        }
        return ['regions' => $regions];
    }

    /**
     * GET /game/regions/{id} — Detail mit Breadcrumb (hoch), Kindern (runter) und
     * In-Gebiet-Bestenliste.
     *
     * @return array<string,mixed>|null
     */
    public function regionDetail(int $id, ?int $viewerClaimant): ?array
    {
        $this->ensureOwnership();
        $r = $this->repo->regionFull($id);
        if ($r === null) {
            return null;
        }
        $path = (string)$r['path'];
        $totalLen = (float)($r['total_game_length_m'] ?? 0.0);

        // Bestenliste (Selbst + Nachfahren über path-Präfix).
        $board = [];
        $rank = 0;
        $me = null;
        foreach ($this->repo->leaderboardByPathPrefix($path, 50) as $entry) {
            $rank++;
            $frac = $totalLen > 0 ? $entry['len'] / $totalLen : 0.0;
            $line = array_merge(
                ['rank' => $rank],
                $this->claimant($entry['claimant_id']) ?? ['claimant_id' => $entry['claimant_id']],
                ['held_length_m' => $entry['len'], 'held_edges' => $entry['edges'], 'held_fraction' => $frac],
            );
            $board[] = $line;
            if ($viewerClaimant !== null && $entry['claimant_id'] === $viewerClaimant) {
                $me = ['rank' => $rank, 'held_length_m' => $entry['len'], 'held_edges' => $entry['edges'], 'held_fraction' => $frac];
            }
        }

        $children = [];
        foreach ($this->repo->childrenOf($id) as $c) {
            $children[] = $this->serializeRegion($c, $viewerClaimant, false);
        }

        $level = (int)$r['level'];
        $minFraction = $this->jsonMap('region_control_min_fraction');

        return [
            'id' => (int)$r['id'],
            'level' => $level,
            'kind' => (string)$r['kind'],
            'name' => (string)$r['name'],
            'country_code' => isset($r['country_code']) && $r['country_code'] !== null ? (string)$r['country_code'] : null,
            'center' => ['lat' => (float)$r['center_lat'], 'lon' => (float)$r['center_lon']],
            'owner' => $this->claimant($this->intOrNull($r['owner_claimant_id'])),
            'leader' => $this->claimant($this->intOrNull($r['leader_claimant_id'])),
            'contested' => (int)($r['contested'] ?? 1) === 1,
            'total_game_length_m' => $totalLen,
            'total_edges' => (int)($r['total_edges'] ?? 0),
            'control_min_fraction' => $minFraction[(string)$level] ?? null,
            'breadcrumb' => $this->repo->ancestors($path, $id),
            'children' => $children,
            'leaderboard' => $board,
            'me' => $me,
        ];
    }

    /**
     * GET /game/me/regions — Gebiete, die der Claimant hält oder anführt.
     *
     * @return array{regions:list<array<string,mixed>>}
     */
    public function myRegions(int $claimantId, ?int $level): array
    {
        $this->ensureOwnership();
        $rows = $this->repo->regionsForClaimant($claimantId, $level);
        $out = [];
        foreach ($rows as $r) {
            $s = $this->serializeRegion($r, $claimantId, false);
            $s['owned'] = $this->intOrNull($r['owner_claimant_id']) === $claimantId;
            $s['contesting'] = !$s['owned'] && $this->intOrNull($r['leader_claimant_id']) === $claimantId;
            $out[] = $s;
        }
        return ['regions' => $out];
    }

    /**
     * Zoom→Ebene aus der bbox-Spanne (Grad). Gröbere Ebenen haben größere
     * Schwellen; Fallback ist die feinste Ebene (8).
     */
    public function levelForSpan(float $span): int
    {
        $breaks = $this->jsonMap('region_level_span_breaks');   // {"2":6.0,"4":1.5,"6":0.4}
        $levels = array_map('intval', array_keys($breaks));
        sort($levels);
        foreach ($levels as $lvl) {
            if ($span > ($breaks[(string)$lvl] ?? INF)) {
                return $lvl;
            }
        }
        return 8;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function serializeRegion(array $r, ?int $viewerClaimant, bool $withGeometry): array
    {
        $owner = $this->intOrNull($r['owner_claimant_id'] ?? null);
        $leader = $this->intOrNull($r['leader_claimant_id'] ?? null);
        $out = [
            'id' => (int)$r['id'],
            'level' => (int)$r['level'],
            'kind' => (string)$r['kind'],
            'name' => (string)$r['name'],
            'parent_id' => $this->intOrNull($r['parent_id'] ?? null),
            'center' => ['lat' => (float)$r['center_lat'], 'lon' => (float)$r['center_lon']],
            'owner' => $this->claimant($owner),
            'leader' => $this->claimant($leader),
            'held_fraction' => (float)($r['held_fraction'] ?? 0.0),
            'contested' => (int)($r['contested'] ?? 1) === 1,
            'mine' => $viewerClaimant !== null && $owner === $viewerClaimant,
            'total_edges' => (int)($r['total_edges'] ?? 0),
            'total_game_length_m' => (float)($r['total_game_length_m'] ?? 0.0),
            'country_code' => isset($r['country_code']) && $r['country_code'] !== null ? (string)$r['country_code'] : null,
        ];
        if (isset($r['min_lat'])) {
            $out['bbox'] = [
                'minLat' => (float)$r['min_lat'], 'minLon' => (float)$r['min_lon'],
                'maxLat' => (float)$r['max_lat'], 'maxLon' => (float)$r['max_lon'],
            ];
        }
        if ($withGeometry && isset($r['boundary_geojson'])) {
            $out['boundary_geojson'] = json_decode((string)$r['boundary_geojson'], true);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    private function claimant(?int $claimantId): ?array
    {
        if ($claimantId === null) {
            return null;
        }
        if (!array_key_exists($claimantId, $this->claimantCache)) {
            $this->claimantCache[$claimantId] = $this->game->claimantInfo($claimantId);
        }
        return $this->claimantCache[$claimantId];
    }

    private function intOrNull(mixed $v): ?int
    {
        return $v === null || $v === false ? null : (int)$v;
    }

    /** @return array<string,float> */
    private function jsonMap(string $key): array
    {
        $decoded = json_decode($this->config->string($key), true);
        $out = [];
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                $out[(string)$k] = (float)$v;
            }
        }
        return $out;
    }
}
