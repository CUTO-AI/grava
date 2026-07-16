<?php
declare(strict_types=1);

namespace App\Integrations\Wahoo;

use adriangibbons\phpFITFileAnalysis;

/**
 * Dekodiert eine rohe FIT-Datei (Wahoo-Workout) in das GeoJSON-Format, das die
 * Route-/Game-Ingestion erwartet — analog zu `StravaService::buildGeoJson`, nur
 * dass die Quelle eine FIT-Binärdatei statt lat/lng-JSON ist.
 *
 * Nutzt {@see phpFITFileAnalysis} (MIT, gepinnt/vendored). Positionen liefert die
 * Lib bereits in Grad, Höhe in Metern, Zeitstempel als Unix. `record`-Felder sind
 * je Zeitstempel indizierte Arrays; wir paaren Lat/Lon/Höhe über denselben Key.
 *
 * Der Fahrt-Startzeitpunkt wird als `properties.startedAt` (ISO-8601 UTC)
 * eingebettet — der stabile Dedup-/Datierungs-Anker der Spiel-Ingestion (vgl.
 * Revier-Verlauf: korrekte Datierung nach Fahrdatum, nicht Verarbeitungszeit).
 */
final class FitDecoder
{
    /**
     * @return array{
     *   geojson:string, started_at:?string, point_count:int,
     *   aggregates:array{avg_power_w:?int,max_power_w:?int,avg_cadence_rpm:?int,
     *                    avg_heart_rate_bpm:?int,max_heart_rate_bpm:?int}
     * }
     * @throws WahooException bei ungültiger/unlesbarer FIT-Datei
     */
    public function decode(string $fitBytes): array
    {
        if ($fitBytes === '') {
            throw new WahooException('fit_empty', 'FIT-Datei ist leer.', 422);
        }
        // Die (bewusst gepinnte, abandoned) Lib nutzt intern FILTER_SANITIZE_STRING
        // (seit PHP 8.1 deprecated). Das ist nur eine Notice — wir unterdrücken sie
        // eng um den Lib-Aufruf, damit sie die Prod-Logs nicht flutet.
        $prevReporting = error_reporting();
        error_reporting($prevReporting & ~E_DEPRECATED);
        try {
            $fit = new phpFITFileAnalysis($fitBytes, ['input_is_data' => true]);
        } catch (\Throwable $e) {
            throw new WahooException('fit_parse_failed', 'FIT-Datei konnte nicht gelesen werden: ' . $e->getMessage(), 422);
        } finally {
            error_reporting($prevReporting);
        }

        $rec = $fit->data_mesgs['record'] ?? [];
        $lat = self::asArray($rec['position_lat'] ?? []);
        $lon = self::asArray($rec['position_long'] ?? []);
        $alt = self::asArray($rec['altitude'] ?? ($rec['enhanced_altitude'] ?? []));

        // Über die Positions-Keys (Zeitstempel) in chronologischer Reihenfolge.
        $keys = array_keys($lat);
        sort($keys, SORT_NUMERIC);

        $coords = [];
        foreach ($keys as $k) {
            if (!isset($lon[$k])) {
                continue;
            }
            $la = (float)$lat[$k];
            $lo = (float)$lon[$k];
            // GeoJSON ist [lon, lat, (alt)].
            if (isset($alt[$k])) {
                $coords[] = [$lo, $la, (float)$alt[$k]];
            } else {
                $coords[] = [$lo, $la];
            }
        }

        $properties = new \stdClass();
        $startedAt = self::startedAt($fit);
        if ($startedAt !== null) {
            $properties->startedAt = $startedAt;
        }

        $geojson = json_encode([
            'type' => 'Feature',
            'properties' => $properties,
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $coords,
            ],
        ], JSON_THROW_ON_ERROR);

        return [
            'geojson'     => $geojson,
            'started_at'  => $startedAt,
            'point_count' => count($coords),
            'aggregates'  => self::aggregates($fit, $rec),
        ];
    }

    /**
     * Startzeit aus `session.start_time` (Unix, von der Lib gesetzt), sonst dem
     * ersten `record`-Zeitstempel — als ISO-8601 UTC.
     */
    private static function startedAt(phpFITFileAnalysis $fit): ?string
    {
        $session = $fit->data_mesgs['session'] ?? [];
        $ts = null;
        if (isset($session['start_time']) && is_numeric($session['start_time'])) {
            $ts = (int)$session['start_time'];
        } else {
            $tsField = self::asArray($fit->data_mesgs['record']['timestamp'] ?? []);
            if ($tsField !== []) {
                $keys = array_keys($tsField);
                sort($keys, SORT_NUMERIC);
                $first = $tsField[$keys[0]];
                $ts = is_numeric($first) ? (int)$first : (int)$keys[0];
            }
        }
        if ($ts === null || $ts <= 0) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * Sensor-Aggregate — bevorzugt die `session`-Werte (vom Gerät berechnet),
     * sonst aus den `record`-Arrays gemittelt/maximiert.
     *
     * @param array<string,mixed> $rec
     * @return array{avg_power_w:?int,max_power_w:?int,avg_cadence_rpm:?int,avg_heart_rate_bpm:?int,max_heart_rate_bpm:?int}
     */
    private static function aggregates(phpFITFileAnalysis $fit, array $rec): array
    {
        $s = $fit->data_mesgs['session'] ?? [];
        return [
            'avg_power_w'        => self::intOrAvg($s['avg_power'] ?? null, $rec['power'] ?? null),
            'max_power_w'        => self::intOrMax($s['max_power'] ?? null, $rec['power'] ?? null),
            'avg_cadence_rpm'    => self::intOrAvg($s['avg_cadence'] ?? null, $rec['cadence'] ?? null),
            'avg_heart_rate_bpm' => self::intOrAvg($s['avg_heart_rate'] ?? null, $rec['heart_rate'] ?? null),
            'max_heart_rate_bpm' => self::intOrMax($s['max_heart_rate'] ?? null, $rec['heart_rate'] ?? null),
        ];
    }

    private static function intOrAvg(mixed $sessionVal, mixed $recField): ?int
    {
        if (is_numeric($sessionVal)) {
            return (int)round((float)$sessionVal);
        }
        $arr = array_values(array_filter(self::asArray($recField), 'is_numeric'));
        if ($arr === []) {
            return null;
        }
        return (int)round(array_sum($arr) / count($arr));
    }

    private static function intOrMax(mixed $sessionVal, mixed $recField): ?int
    {
        if (is_numeric($sessionVal)) {
            return (int)round((float)$sessionVal);
        }
        $arr = array_values(array_filter(self::asArray($recField), 'is_numeric'));
        if ($arr === []) {
            return null;
        }
        return (int)round(max($arr));
    }

    /**
     * Die Lib liefert record-Felder als (zeitstempel-indiziertes) Array, bei nur
     * einem Datensatz aber als Skalar. Normalisiert auf ein Array.
     *
     * @return array<int|string,mixed>
     */
    private static function asArray(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if ($v === null || $v === '') {
            return [];
        }
        return [$v];
    }
}
