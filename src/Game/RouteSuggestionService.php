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
        int $maxWaypoints = 40,
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
        // Volle Kandidatenliste merken — die Greedy-Auswahl (array_splice) leert
        // $cands; danach zählen wir ALLE eroberbaren Kanten ENTLANG der Route.
        $allCands = $cands;

        // Dichte-Sweep (Nearest-Next): von der aktuellen Position immer die
        // NÄCHSTGELEGENE noch offene eroberbare Kante nehmen, solange (Weg +
        // Rückweg) ins Budget passt. Das packt möglichst VIELE Kanten in die Runde
        // (Ziel: viel Neuland einsammeln), statt zu einzelnen hochwertigen Kanten
        // zu springen.
        // Mindestabstand zwischen Wegpunkten: verteilt die (begrenzten) Wegpunkte
        // über die Fläche, statt sie im dichtesten Fleck zu clustern → die Route
        // füllt das Distanz-Budget und streift so VIEL mehr eroberbare Kanten
        // (die dazwischen zählt der Along-Route-Schritt unten). Skaliert mit dem
        // Budget; bei zu strenger Spreizung (zu wenige Wegpunkte gefunden) lockern.
        $spacingM = max(150.0, $budgetM / ($maxWaypoints * 2.0));
        $selected = $this->greedySpread($cands, $startLat, $startLon, $budgetM, $loop, $maxWaypoints, $spacingM);
        // Falls die Spreizung zu wenige Wegpunkte fand (dichte Kanten liegen enger
        // beieinander als der Abstand), ohne Mindestabstand erneut (dichter Sweep).
        if (count($selected) < min(8, count($cands))) {
            $selected = $this->greedySpread($cands, $startLat, $startLon, $budgetM, $loop, $maxWaypoints, 0.0);
        }
        if ($selected === []) {
            return ['reason' => 'no_candidates'];
        }

        // Route bauen; scheitert es mit vielen Wegpunkten (evtl. Valhallas
        // max_locations), einmal mit weniger Wegpunkten erneut versuchen.
        $route = $this->routeThrough($selected, $startLat, $startLon, $loop);
        if ($route === null && count($selected) > 20) {
            $selected = array_slice($selected, 0, 20);
            $route = $this->routeThrough($selected, $startLat, $startLon, $loop);
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

        // Eroberbare Kanten ENTLANG der Route zählen (nicht nur die Wegpunkte):
        // ein Kandidat gilt als erobert, wenn sein Mittelpunkt nahe der Routenlinie
        // liegt — so spiegelt die Zahl wider, was man beim Fahren tatsächlich flippt.
        $coords = $route['coordinates'];
        [$rMinLon, $rMinLat, $rMaxLon, $rMaxLat] = self::bounds($coords);
        $bufDeg = 0.001; // ~100 m bbox-Puffer für den Vorfilter
        $captureThresholdM = 50.0;

        $capturedValue = 0.0;
        $ids = [];
        foreach ($allCands as $c) {
            if ($c['lon'] < $rMinLon - $bufDeg || $c['lon'] > $rMaxLon + $bufDeg
                || $c['lat'] < $rMinLat - $bufDeg || $c['lat'] > $rMaxLat + $bufDeg) {
                continue; // klar außerhalb der Route → überspringen (billig)
            }
            if (self::pointToPolylineM($c['lat'], $c['lon'], $coords) <= $captureThresholdM) {
                $ids[] = $c['id'];
                $capturedValue += $c['value'];
            }
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
            'geometry'       => ['type' => 'LineString', 'coordinates' => $coords],
        ];
    }

    /**
     * Nearest-Next-Auswahl mit optionalem Mindestabstand zwischen Wegpunkten.
     * `$cands` wird per Wert übergeben (interne array_splice mutiert den Aufrufer
     * NICHT) → mehrfach aufrufbar. `$spacingM = 0` → reiner dichter Sweep.
     *
     * @param list<array{id:int,lat:float,lon:float,value:float}> $cands
     * @return list<array{id:int,lat:float,lon:float,value:float}>
     */
    private function greedySpread(
        array $cands, float $startLat, float $startLon,
        float $budgetM, bool $loop, int $maxWaypoints, float $spacingM,
    ): array {
        $selected = [];
        $curLat = $startLat;
        $curLon = $startLon;
        $usedM = 0.0;
        while ($cands !== [] && count($selected) < $maxWaypoints) {
            $bestIdx = -1;
            $bestLegM = INF;
            foreach ($cands as $i => $c) {
                $legM = self::haversineM($curLat, $curLon, $c['lat'], $c['lon']);
                $retM = $loop ? self::haversineM($c['lat'], $c['lon'], $startLat, $startLon) : 0.0;
                if ($usedM + $legM + $retM > $budgetM) {
                    continue;
                }
                if ($spacingM > 0.0) {
                    $tooClose = false;
                    foreach ($selected as $s) {
                        if (self::haversineM($s['lat'], $s['lon'], $c['lat'], $c['lon']) < $spacingM) {
                            $tooClose = true;
                            break;
                        }
                    }
                    if ($tooClose) {
                        continue;
                    }
                }
                if ($legM < $bestLegM) {
                    $bestLegM = $legM;
                    $bestIdx = $i;
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
        return $selected;
    }

    /**
     * Baut die Route durch die gewählten Kanten (Start → Wegpunkte → ggf. Start).
     * Fängt Valhalla-Ausfälle ab (kein 500) → null bei Fehlschlag.
     *
     * @param list<array{id:int,lat:float,lon:float,value:float}> $selected
     * @return array{distance_m:float,duration_s:float,coordinates:list<array{0:float,1:float}>}|null
     */
    private function routeThrough(array $selected, float $startLat, float $startLon, bool $loop): ?array
    {
        $locations = [['lat' => $startLat, 'lon' => $startLon]];
        foreach ($selected as $c) {
            $locations[] = ['lat' => $c['lat'], 'lon' => $c['lon']];
        }
        if ($loop) {
            $locations[] = ['lat' => $startLat, 'lon' => $startLon];
        }
        try {
            return $this->valhalla->optimizedRoute($locations);
        } catch (\Throwable $e) {
            error_log('RouteSuggestion: Valhalla Exception: ' . $e->getMessage());
            return null;
        }
    }

    private static function haversineM(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * bbox einer [lon,lat]-Punktliste → [minLon, minLat, maxLon, maxLat].
     *
     * @param list<array{0:float,1:float}> $coords
     * @return array{0:float,1:float,2:float,3:float}
     */
    private static function bounds(array $coords): array
    {
        $minLon = INF; $minLat = INF; $maxLon = -INF; $maxLat = -INF;
        foreach ($coords as $c) {
            $lon = (float)$c[0]; $lat = (float)$c[1];
            $minLon = min($minLon, $lon); $maxLon = max($maxLon, $lon);
            $minLat = min($minLat, $lat); $maxLat = max($maxLat, $lat);
        }
        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    /**
     * Kürzester Abstand (Meter) eines Punkts zur Polylinie (Punkt-zu-Segment,
     * äquirektanguläre Projektion um den Punkt — bei diesen Distanzen genau genug).
     *
     * @param list<array{0:float,1:float}> $coords [lon,lat]-Paare
     */
    private static function pointToPolylineM(float $lat, float $lon, array $coords): float
    {
        $mPerDegLat = 111320.0;
        $mPerDegLon = 111320.0 * cos(deg2rad($lat));
        $best = INF;
        $prevX = null; $prevY = null;
        foreach ($coords as $c) {
            $x = ((float)$c[0] - $lon) * $mPerDegLon;
            $y = ((float)$c[1] - $lat) * $mPerDegLat;
            if ($prevX !== null) {
                $best = min($best, self::segDistM($x, $y, $prevX, $prevY));
                if ($best === 0.0) {
                    return 0.0;
                }
            }
            $prevX = $x; $prevY = $y;
        }
        return $best;
    }

    /** Abstand des Ursprungs (0,0) zum Segment A→B in Metern (planar). */
    private static function segDistM(float $ax, float $ay, float $bx, float $by): float
    {
        $dx = $bx - $ax; $dy = $by - $ay;
        $l2 = $dx * $dx + $dy * $dy;
        if ($l2 <= 0.0) {
            return sqrt($ax * $ax + $ay * $ay);
        }
        $t = max(0.0, min(1.0, -($ax * $dx + $ay * $dy) / $l2));
        $cx = $ax + $t * $dx; $cy = $ay + $t * $dy;
        return sqrt($cx * $cx + $cy * $cy);
    }
}
