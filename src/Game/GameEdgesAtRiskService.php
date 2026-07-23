<?php
declare(strict_types=1);

namespace App\Game;

use App\Privacy\PrivacyZone;
use App\Privacy\PrivacyZoneRepository;
use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * „Kanten in Gefahr" — read-only Ableitung aus Präsenz (GAME_EDGES_AT_RISK_BACKEND.md).
 */
final class GameEdgesAtRiskService
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameConfig $config,
        private readonly EdgeRecalculator $recalc,
        private readonly PrivacyZoneRepository $privacyZones,
    ) {}

    /**
     * Cache-Fassade für den API-Endpoint: berechnet EINMAL, speichert die fertige
     * Antwort (game_at_risk_cache) und liefert danach aus dem Cache. Abgelaufene
     * Einträge erneuert der Cron `game:at-risk-refresh`; kleine Konten (bis
     * `at_risk_live_max_edges` gehaltene Kanten) rechnen auch bei abgelaufenem
     * Cache live, Power-Konten liefern die (leicht veraltete) Kopie sofort —
     * die Live-Ableitung lief dort sonst in den 20-s-Client-Timeout.
     *
     * @return array{at_risk_count:int,fading_count:int,edges:list<array<string,mixed>>}
     */
    public function atRiskCached(int $userId): array
    {
        $cached = $this->repo->atRiskCacheGet($userId);
        if ($cached !== null) {
            $payload = json_decode($cached['payload'], true);
            if (is_array($payload)) {
                $ttlMin = max(0, $this->config->int('at_risk_cache_ttl_min'));
                $ageSec = time() - strtotime($cached['computed_at'] . ' UTC');
                if ($ageSec < $ttlMin * 60) {
                    return $payload;
                }
                // Abgelaufen: nur kleine Konten live nachrechnen, große liefern
                // die Kopie (Refresh übernimmt der Cron).
                $claimantId = $this->repo->effectiveClaimantId($userId);
                if ($this->repo->heldEdgeCountByClaimant($claimantId) > $this->config->int('at_risk_live_max_edges')) {
                    return $payload;
                }
            }
        }
        return $this->computeAndStore($userId);
    }

    /** Berechnet frisch und legt die Antwort im Cache ab (gemeinsamer Pfad API/Cron). */
    public function computeAndStore(int $userId): array
    {
        $fresh = $this->atRisk($userId);
        $json = json_encode($fresh, JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            $this->repo->atRiskCachePut($userId, $json);
        }
        return $fresh;
    }

    /**
     * Cron-Pfad (`game:at-risk-refresh`): erneuert die ältesten abgelaufenen
     * Cache-Einträge. Nur User, die den Endpoint schon einmal benutzt haben,
     * besitzen eine Zeile — Karteileichen kosten nichts.
     *
     * @return array{checked:int,refreshed:int}
     */
    public function refreshStale(int $limit = 200): array
    {
        $ttlMin = max(0, $this->config->int('at_risk_cache_ttl_min'));
        $stale = $this->repo->atRiskCacheStaleUserIds($ttlMin, $limit);
        foreach ($stale as $uid) {
            $this->computeAndStore($uid);
        }
        return ['checked' => count($stale), 'refreshed' => count($stale)];
    }

    /**
     * Reine Live-Ableitung (ohne Cache) — direkter Aufruf nur für Tests/Cron;
     * der API-Endpoint nutzt {@see atRiskCached()}.
     *
     * @return array{at_risk_count:int,fading_count:int,edges:list<array<string,mixed>>}
     */
    public function atRisk(int $userId): array
    {
        $now = Clock::nowUtc();
        $claimantId = $this->repo->effectiveClaimantId($userId);
        $zone = $this->viewerPrivacyZone($userId);

        $riskThreshold = $this->config->float('risk_threshold');
        $fadeThreshold = $this->config->float('fade_threshold');
        $listLimit = max(1, $this->config->int('at_risk_list_limit'));

        $challenged = [];
        $fading = [];

        foreach ($this->repo->heldEdgesByClaimant($claimantId) as $row) {
            if ($this->isInPrivacyZone($row, $zone)) {
                continue;
            }

            $edgeId = (int)$row['id'];
            // Präsenz + Besitzer-Frische in EINEM Pass-Scan (statt zweier Aufrufe
            // pro Kante — halbiert die Query-Last dieses N-Kanten-Loops).
            [$presence, $ownerFreshness] = $this->recalc->presenceWithOwnerFreshness($edgeId, $claimantId, $now);
            $ownerPresence = $presence[$claimantId] ?? 0.0;
            [$challengerId, $challengerPresence] = $this->topChallenger($presence, $claimantId);

            if ($challengerId !== null
                && $ownerPresence > 0.0
                && $challengerPresence >= $ownerPresence * $riskThreshold
            ) {
                $challenged[] = $this->formatChallengedEdge(
                    $row,
                    $ownerPresence,
                    $challengerPresence,
                    $challengerId,
                    $ownerPresence > 0.0 ? $challengerPresence / $ownerPresence : 0.0,
                );
                continue;
            }

            if ($fadeThreshold > 0.0 && ($challengerPresence <= 0.0 || $challengerPresence < $ownerPresence * $riskThreshold)) {
                if ($ownerFreshness <= $fadeThreshold) {
                    $fading[] = $this->formatFadingEdge($row, $ownerFreshness);
                }
            }
        }

        usort(
            $challenged,
            static fn(array $a, array $b): int => $b['_urgency'] <=> $a['_urgency'],
        );
        usort(
            $fading,
            static fn(array $a, array $b): int => $a['owner_presence'] <=> $b['owner_presence'],
        );

        foreach ($challenged as &$e) {
            unset($e['_urgency']);
        }
        unset($e);

        $edges = array_slice(
            array_merge($challenged, $fading),
            0,
            $listLimit,
        );

        return [
            'at_risk_count' => count($challenged),
            'fading_count'  => count($fading),
            'edges'         => $edges,
        ];
    }

    /** @param array<int,float> $presence */
    private function topChallenger(array $presence, int $ownerId): array
    {
        $bestId = null;
        $bestPres = 0.0;
        foreach ($presence as $cid => $pres) {
            if ((int)$cid === $ownerId) {
                continue;
            }
            if ($pres > $bestPres) {
                $bestPres = $pres;
                $bestId = (int)$cid;
            }
        }
        return [$bestId, $bestPres];
    }

    /** @param array<string,mixed> $row */
    private function formatChallengedEdge(
        array $row,
        float $ownerPresence,
        float $challengerPresence,
        int $challengerId,
        float $urgency,
    ): array {
        $out = [
            'edge_id'              => (int)$row['id'],
            'reason'               => 'challenged',
            'owner_presence'       => round($ownerPresence, 1),
            'challenger_presence'  => round($challengerPresence, 1),
            'lat'                  => ($row['min_lat'] + $row['max_lat']) / 2.0,
            'lon'                  => ($row['min_lon'] + $row['max_lon']) / 2.0,
            '_urgency'             => $urgency,
        ];

        $info = $this->repo->claimantInfo($challengerId);
        if ($info !== null && ($info['type'] ?? '') === 'rider' && ($info['handle'] ?? null) !== null) {
            $out['challenger_handle'] = (string)$info['handle'];
        }

        return $out;
    }

    /** @param array<string,mixed> $row */
    private function formatFadingEdge(array $row, float $freshness): array
    {
        return [
            'edge_id'        => (int)$row['id'],
            'reason'         => 'fading',
            'owner_presence' => round($freshness, 2),
            'lat'            => ($row['min_lat'] + $row['max_lat']) / 2.0,
            'lon'            => ($row['min_lon'] + $row['max_lon']) / 2.0,
        ];
    }

    private function viewerPrivacyZone(int $userId): ?PrivacyZone
    {
        $row = $this->privacyZones->find($userId);
        if ($row === null || !$row['enabled']) {
            return null;
        }
        return new PrivacyZone($row['lat'], $row['lon'], $row['radius_m']);
    }

    /** @param array<string,mixed> $row */
    private function isInPrivacyZone(array $row, ?PrivacyZone $zone): bool
    {
        if ($zone === null) {
            return false;
        }
        $geom = json_decode((string)($row['geom_geojson'] ?? ''), true);
        $coords = is_array($geom) ? ($geom['coordinates'] ?? null) : null;
        return is_array($coords) && $zone->intersectsPolyline($coords);
    }
}
