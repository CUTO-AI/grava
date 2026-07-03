<?php
declare(strict_types=1);

namespace App\Game;

use App\Support\Clock;

/**
 * Berechnet den Gebiets-Besitz (game_region_ownership) für die Gebiets-Eroberung
 * (CityConquest_Backend_Spec.md, Phase B): direkte Blatt-Besitzsummen aus
 * game_edge werden per Bottom-up-Rollup entlang der Hierarchie (path) auf alle
 * Ahnen aufaddiert; je Gebiet bestimmt die Kontrollschwelle der jeweiligen Ebene,
 * ob der Führende auch Eigentümer wird oder das Gebiet „umkämpft" bleibt.
 *
 * Voller Recompute ist günstig, weil nur Gebiete MIT Spielkanten (plus deren
 * Ahnen) eine Cache-Zeile bekommen — Spielkanten entstehen nur dort, wo gefahren
 * wird. recomputeAll() liefert die Besitzwechsel zurück (für region_taken/
 * region_lost-Events).
 */
final class RegionOwnershipService
{
    public function __construct(
        private readonly RegionRepository $repo,
        private readonly GameConfig $config,
    ) {}

    /**
     * @return array{regions:int,changes:list<array{region_id:int,level:int,old_owner:?int,new_owner:?int}>}
     */
    public function recomputeAll(?string $now = null): array
    {
        $now ??= Clock::nowUtcString();

        $ownableLevels = $this->jsonList('region_ownable_levels');
        $minFraction = $this->jsonMap('region_control_min_fraction');
        $minEdges = $this->jsonMap('region_control_min_edges');

        $totals = $this->repo->directTotals();
        $direct = $this->repo->directOwnershipSums();

        // Ahnenpfade der Blatt-Gebiete (die mit direkten Kanten).
        $leafIds = array_values(array_unique(array_map(static fn($r) => $r['region_id'], $totals)));
        $leafMeta = $this->repo->metaForRegions($leafIds);

        /** @var array<int,array{len:float,edges:int}> $accTotal */
        $accTotal = [];
        /** @var array<int,array<int,array{len:float,edges:int}>> $accOwner */
        $accOwner = [];

        foreach ($totals as $t) {
            $path = $leafMeta[$t['region_id']]['path'] ?? null;
            if ($path === null) {
                continue;
            }
            foreach ($this->ancestors($path) as $rid) {
                $accTotal[$rid]['len'] = ($accTotal[$rid]['len'] ?? 0.0) + $t['len'];
                $accTotal[$rid]['edges'] = ($accTotal[$rid]['edges'] ?? 0) + $t['edges'];
            }
        }
        foreach ($direct as $d) {
            $path = $leafMeta[$d['region_id']]['path'] ?? null;
            if ($path === null) {
                continue;
            }
            foreach ($this->ancestors($path) as $rid) {
                $accOwner[$rid][$d['claimant_id']]['len'] = ($accOwner[$rid][$d['claimant_id']]['len'] ?? 0.0) + $d['len'];
                $accOwner[$rid][$d['claimant_id']]['edges'] = ($accOwner[$rid][$d['claimant_id']]['edges'] ?? 0) + $d['edges'];
            }
        }

        $allIds = array_keys($accTotal);
        $meta = $this->repo->metaForRegions($allIds);
        $prev = $this->repo->currentOwnersFor($allIds);

        $changes = [];
        $keep = [];
        foreach ($accTotal as $rid => $tot) {
            $level = $meta[$rid]['level'] ?? 0;

            // Führenden bestimmen (Länge desc, dann kleinste claimant_id → deterministisch).
            $leaderId = null; $leaderLen = -1.0; $leaderEdges = 0;
            foreach ($accOwner[$rid] ?? [] as $cid => $s) {
                if ($s['len'] > $leaderLen || ($s['len'] === $leaderLen && ($leaderId === null || $cid < $leaderId))) {
                    $leaderId = (int)$cid; $leaderLen = $s['len']; $leaderEdges = $s['edges'];
                }
            }
            $totLen = $tot['len'];
            $frac = $totLen > 0 ? ($leaderLen > 0 ? $leaderLen / $totLen : 0.0) : 0.0;

            $ownable = in_array($level, $ownableLevels, true);
            $needFrac = $minFraction[(string)$level] ?? INF;
            $needEdges = $minEdges[(string)$level] ?? PHP_INT_MAX;
            $isOwned = $ownable && $leaderId !== null && $frac >= $needFrac && $leaderEdges >= $needEdges;
            $owner = $isOwned ? $leaderId : null;

            $prevOwner = $prev[$rid]['owner'] ?? null;
            if ($owner !== null && $owner === $prevOwner) {
                $since = $prev[$rid]['since'] ?? $now;    // Besitz gehalten → Zeitpunkt behalten
            } else {
                $since = $owner !== null ? $now : null;   // neuer Besitz / verloren
            }

            $this->repo->upsertOwnership([
                'region_id' => $rid,
                'owner_claimant_id' => $owner,
                'leader_claimant_id' => $leaderId,
                'owner_held_length_m' => $isOwned ? $leaderLen : ($leaderLen > 0 ? $leaderLen : 0.0),
                'owner_held_edges' => $isOwned ? $leaderEdges : ($leaderId !== null ? $leaderEdges : 0),
                'total_game_length_m' => $totLen,
                'total_edges' => $tot['edges'],
                'held_fraction' => $frac,
                'contested' => $owner === null ? 1 : 0,
                'owner_since' => $since,
            ]);
            $keep[] = $rid;

            if ($owner !== $prevOwner) {
                $changes[] = ['region_id' => $rid, 'level' => $level, 'old_owner' => $prevOwner, 'new_owner' => $owner];
            }
        }

        $this->repo->deleteOwnershipExcept($keep);

        return ['regions' => count($keep), 'changes' => $changes];
    }

    /**
     * Ahnen-IDs (inkl. Selbst) aus dem materialisierten path '/a/b/c/'.
     *
     * @return list<int>
     */
    private function ancestors(string $path): array
    {
        $ids = array_filter(explode('/', trim($path, '/')), static fn($s) => $s !== '');
        return array_map('intval', array_values($ids));
    }

    /** @return list<int> */
    private function jsonList(string $key): array
    {
        $decoded = json_decode($this->config->string($key), true);
        return is_array($decoded) ? array_map('intval', array_values($decoded)) : [];
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
