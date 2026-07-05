<?php
declare(strict_types=1);

namespace App\Heatmap;

/**
 * Wählt anhand der Fahrt-Koordinaten die passende Valhalla-Instanz (z. B. Europa
 * vs. Nordamerika) und delegiert an deren {@see ValhallaClient}. Hintergrund: eine
 * Planet-Kachelmenge ist teuer (Build-Zeit, RAM, Disk); pro Kontinent ein eigener,
 * kleiner Tileset-Server ist günstiger und unabhängig aktualisierbar. Da eine
 * einzelne Radfahrt nie einen Ozean quert, genügt der ERSTE gültige Punkt der Spur
 * zur eindeutigen Zuordnung über disjunkte Bounding-Boxen.
 *
 * Erweitert {@see ValhallaClient}, damit bestehende Konsumenten (die konkret
 * `ValhallaClient` erwarten) unverändert weiterlaufen — Liskov-konform überschrieben
 * werden nur {@see matchTrace()} (Regions-Routing) und {@see status()} (Primärregion).
 */
final class RegionalValhallaClient extends ValhallaClient
{
    /**
     * @param list<array{name:string,bbox:array{0:float,1:float,2:float,3:float},client:ValhallaClient}> $regions
     *        bbox = [minLon, minLat, maxLon, maxLat]; disjunkt gedacht.
     * @param ValhallaClient $fallback genutzt, wenn kein bbox greift (Grenzfälle, See).
     */
    public function __construct(
        private readonly array $regions,
        private readonly ValhallaClient $fallback,
        string $fallbackBaseUrl,
        string $costing = 'bicycle',
    ) {
        parent::__construct($fallbackBaseUrl, $costing);
    }

    public function matchTrace(array $points): ?ValhallaMatch
    {
        return $this->pick($points)->matchTrace($points);
    }

    /** Die für diese Spur zuständige Instanz (erster Punkt in einer bbox gewinnt). */
    private function pick(array $points): ValhallaClient
    {
        foreach ($points as $p) {
            if (!isset($p['lat'], $p['lon'])) {
                continue;
            }
            $lat = (float)$p['lat'];
            $lon = (float)$p['lon'];
            if ($lat === 0.0 && $lon === 0.0) {
                continue;
            }
            foreach ($this->regions as $r) {
                [$minLon, $minLat, $maxLon, $maxLat] = $r['bbox'];
                if ($lon >= $minLon && $lon <= $maxLon && $lat >= $minLat && $lat <= $maxLat) {
                    return $r['client'];
                }
            }
            break; // erster gültiger Punkt entscheidet; kein Treffer → Fallback
        }
        return $this->fallback;
    }

    /** Primärregion (erste konfigurierte) für den schlanken Health-Ping/Anzeige. */
    public function status(): array
    {
        $primary = $this->regions[0]['client'] ?? $this->fallback;
        return $primary->status();
    }

    /**
     * Status je konfigurierter Region — für /healthz?check=valhalla und das
     * Admin-Dashboard, damit man beide Tunnel/Instanzen auf einen Blick sieht.
     *
     * @return list<array{name:string,reachable:bool,base_url:string,version:?string,
     *                     tileset_last_modified:?string,latency_ms:?int,error:?string}>
     */
    public function statuses(): array
    {
        $out = [];
        foreach ($this->regions as $r) {
            $out[] = ['name' => $r['name']] + $r['client']->status();
        }
        return $out;
    }
}
