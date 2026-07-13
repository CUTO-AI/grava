<?php
declare(strict_types=1);

namespace App\Game;

use App\Heatmap\ValhallaClient;

/**
 * Automatischer Eroberungs-Routenvorschlag (RouteSuggestion_Concept.md, Pro-Feature).
 *
 * Aus den in der Nähe eroberbaren Kanten (`in_reach`, GAME_IN_REACH_BACKEND.md)
 * wird eine fahrbare Runde im Distanz-Budget gebaut: Kandidaten holen → greedy
 * eine wertvolle Auswahl unter Budget wählen (Wert pro Zusatz-Luftlinie) →
 * Wegpunkt-Reihenfolge + echte Strecke via Valhalla `/optimized_route`.
 *
 * FAIRNESS (§1): nutzt ausschließlich die ohnehin frei sichtbaren „Chancen"-Kanten
 * und packt sie in eine bequeme Route — kein Spielvorteil, reiner Komfort.
 */
final class RouteSuggestionService
{
    public function __construct(
        private readonly GameReadService $read,
        private readonly ValhallaClient $valhalla,
    ) {}

    /**
     * @return array<string,mixed>  immer mit Schlüssel `reason`:
     *   'ok'             → Vorschlag (distance_m, captured_*, geometry, …)
     *   'no_candidates'  → keine eroberbaren Kanten in Reichweite
     *   'routing_failed' → Kandidaten da, aber Valhalla lieferte keine Route
     */
    public function suggest(
        int $claimantId,
        ?int $viewerUserId,
        float $startLat,
        float $startLon,
        float $maxKm,
        bool $loop = true,
        int $maxWaypoints = 20,
    ): ?array {
        $maxKm = max(2.0, min(200.0, $maxKm));
        $budgetM = $maxKm * 1000.0;

        // bbox um den Start; Radius ~ Budget/2 (+Puffer), damit Hin- UND Rückweg
        // ins Budget passen.
        $radiusKm = $maxKm / 2.0 + 1.0;
        $dLat = $radiusKm / 111.0;
        $dLon = $radiusKm / (111.0 * max(0.2, cos(deg2rad($startLat))));

        // Eroberbare (in_reach) Kandidaten: nicht-eigene Kanten, nach Nähe zum Start
        // sortiert und besitz-gefiltert (sonst würde ein id-basiertes Limit in
        // dichten Gegenden die wenigen eroberbaren Kanten wegschneiden). Kandidaten
        // kommen bereits als {id, lat, lon (Mittelpunkt), value}. Limit 1500 deckt
        // auch große Budgets ab und hält die Präsenz-Query bezahlbar.
        $cands = $this->read->routeSuggestionCandidates(
            $startLon - $dLon, $startLat - $dLat, $startLon + $dLon, $startLat + $dLat,
            $claimantId, $viewerUserId, $startLat, $startLon, 1500
        );
        $candidateCount = count($cands);
        if ($cands === []) {
            return ['reason' => 'no_candidates'];
        }

        // Greedy: von der aktuellen Position die Kante mit bestem Wert pro
        // Zusatz-Luftliniendistanz wählen, solange (Weg + Rückweg) ins Budget passt.
        $selected = [];
        $curLat = $startLat;
        $curLon = $startLon;
        $usedM = 0.0;
        while ($cands !== [] && count($selected) < $maxWaypoints) {
            $bestIdx = -1;
            $bestScore = -1.0;
            $bestLegM = 0.0;
            foreach ($cands as $i => $c) {
                $legM = self::haversineM($curLat, $curLon, $c['lat'], $c['lon']);
                $retM = $loop ? self::haversineM($c['lat'], $c['lon'], $startLat, $startLon) : 0.0;
                if ($usedM + $legM + $retM > $budgetM) {
                    continue;
                }
                $score = $c['value'] / max(50.0, $legM);   // Wert je Meter Umweg
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIdx = $i;
                    $bestLegM = $legM;
                }
            }
            if ($bestIdx < 0) {
                break;
            }
            $c = $cands[$bestIdx];
            $selected[] = $c;
            $usedM += $bestLegM;
            $curLat = $c['lat'];
            $curLon = $c['lon'];
            array_splice($cands, $bestIdx, 1);
        }
        if ($selected === []) {
            return ['reason' => 'no_candidates'];
        }

        // Wegpunkte: Start, gewählte Kanten-Mittelpunkte, (Rundtour: zurück zum Start).
        $locations = [['lat' => $startLat, 'lon' => $startLon]];
        foreach ($selected as $c) {
            $locations[] = ['lat' => $c['lat'], 'lon' => $c['lon']];
        }
        if ($loop) {
            $locations[] = ['lat' => $startLat, 'lon' => $startLon];
        }

        // Valhalla kann bei Transport-/5xx-Fehlern eine ValhallaUnavailableException
        // werfen (so der Map-Matching-Pfad für Retries). Hier wollen wir sauber
        // degradieren, nicht 500en → abfangen und als routing_failed behandeln.
        try {
            $route = $this->valhalla->optimizedRoute($locations);
        } catch (\Throwable $e) {
            error_log('RouteSuggestion: Valhalla optimizedRoute Exception: ' . $e->getMessage());
            $route = null;
        }
        if ($route === null) {
            // Kandidaten waren da, aber Valhalla konnte keine fahrbare Runde bilden
            // (z. B. Wegpunkt nicht ans Routing-Netz snappbar). Für die Diagnose
            // getrennt vom „keine Kandidaten"-Fall melden.
            error_log(sprintf(
                'RouteSuggestion routing_failed: start=%.5f,%.5f waypoints=%d candidates=%d selected=%d costing/instanz siehe Valhalla-Log',
                $startLat, $startLon, count($locations), $candidateCount, count($selected)
            ));
            return ['reason' => 'routing_failed', 'candidate_count' => $candidateCount, 'selected_count' => count($selected)];
        }

        $capturedValue = 0.0;
        $ids = [];
        foreach ($selected as $c) {
            $capturedValue += $c['value'];
            $ids[] = $c['id'];
        }

        return [
            'reason'         => 'ok',
            'distance_m'     => round($route['distance_m'], 1),
            'duration_s_est' => (int)round($route['duration_s']),
            'captured_edges' => $ids,
            'captured_count' => count($ids),
            'captured_value' => round($capturedValue, 1),
            'candidate_count' => $candidateCount,
            // GeoJSON LineString (coordinates = [lon, lat]) — direkt karten-/GPX-fähig.
            'geometry'       => ['type' => 'LineString', 'coordinates' => $route['coordinates']],
        ];
    }

    private static function haversineM(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
