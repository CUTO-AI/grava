<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Reine Geometrie-Helfer für die Gebiets-Eroberung (CityConquest_Backend_Spec.md):
 * Point-in-Polygon (GeoJSON Polygon/MultiPolygon), bbox, ein repräsentativer
 * Ankerpunkt und Douglas-Peucker-Vereinfachung der Ringe.
 *
 * Bewusst ohne Seiteneffekte und ohne GEOS/GDAL-Abhängigkeit — dieselbe Routine
 * trägt den einmaligen Grenzen-Import, den Edge→Gebiet-Backfill und die
 * Zuordnung neuer Kanten am Ingest. GeoJSON-Koordinaten sind [lon, lat]
 * (RFC 7946). Antimeridian-Wrap wird (wie MapLod) ignoriert — regionaler Betrieb.
 */
final class GeoPolygon
{
    /**
     * Enthält die GeoJSON-Geometrie (Polygon|MultiPolygon) den Punkt?
     * Für Polygone gilt: im äußeren Ring UND in keinem Loch. Für MultiPolygone:
     * in mindestens einem Teilpolygon.
     *
     * @param array<string,mixed> $geometry GeoJSON-Geometrieobjekt
     */
    public static function contains(float $lat, float $lon, array $geometry): bool
    {
        $type = $geometry['type'] ?? null;
        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords)) {
            return false;
        }
        if ($type === 'Polygon') {
            return self::polygonContains($coords, $lon, $lat);
        }
        if ($type === 'MultiPolygon') {
            foreach ($coords as $polygon) {
                if (is_array($polygon) && self::polygonContains($polygon, $lon, $lat)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Ein Polygon = Liste von Ringen: [0] = äußerer Ring, [1..] = Löcher.
     *
     * @param array<int,mixed> $rings
     */
    private static function polygonContains(array $rings, float $lon, float $lat): bool
    {
        if ($rings === [] || !is_array($rings[0])) {
            return false;
        }
        if (!self::ringContains($rings[0], $lon, $lat)) {
            return false;
        }
        $count = count($rings);
        for ($i = 1; $i < $count; $i++) {
            if (is_array($rings[$i]) && self::ringContains($rings[$i], $lon, $lat)) {
                return false; // im Loch
            }
        }
        return true;
    }

    /**
     * Ray-Casting (even-odd) für einen einzelnen Ring [[lon,lat], …].
     *
     * @param array<int,mixed> $ring
     */
    public static function ringContains(array $ring, float $lon, float $lat): bool
    {
        $n = count($ring);
        if ($n < 3) {
            return false;
        }
        $inside = false;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $pi = $ring[$i];
            $pj = $ring[$j];
            if (!is_array($pi) || !is_array($pj)) {
                continue;
            }
            $xi = (float)$pi[0]; $yi = (float)$pi[1];
            $xj = (float)$pj[0]; $yj = (float)$pj[1];
            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersects) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /**
     * BBox der Geometrie.
     *
     * @param array<string,mixed> $geometry
     * @return array{minLat:float,minLon:float,maxLat:float,maxLon:float}|null
     */
    public static function bbox(array $geometry): ?array
    {
        $minLat = INF; $minLon = INF; $maxLat = -INF; $maxLon = -INF;
        $seen = false;
        self::eachCoord($geometry, function (float $lon, float $lat) use (&$minLat, &$minLon, &$maxLat, &$maxLon, &$seen): void {
            $seen = true;
            if ($lat < $minLat) { $minLat = $lat; }
            if ($lat > $maxLat) { $maxLat = $lat; }
            if ($lon < $minLon) { $minLon = $lon; }
            if ($lon > $maxLon) { $maxLon = $lon; }
        });
        if (!$seen) {
            return null;
        }
        return ['minLat' => $minLat, 'minLon' => $minLon, 'maxLat' => $maxLat, 'maxLon' => $maxLon];
    }

    /**
     * Repräsentativer Ankerpunkt (Label/Kamera): flächengewichteter Schwerpunkt
     * der äußeren Ringe. Fällt er (bei stark konkaven Formen) aus dem Polygon,
     * bleibt er dennoch ein brauchbarer Kamera-Anker; für „im Polygon garantiert"
     * ist er nicht gedacht.
     *
     * @param array<string,mixed> $geometry
     * @return array{lat:float,lon:float}|null
     */
    public static function representativePoint(array $geometry): ?array
    {
        $type = $geometry['type'] ?? null;
        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords)) {
            return null;
        }
        $outerRings = [];
        if ($type === 'Polygon') {
            if (isset($coords[0]) && is_array($coords[0])) {
                $outerRings[] = $coords[0];
            }
        } elseif ($type === 'MultiPolygon') {
            foreach ($coords as $polygon) {
                if (is_array($polygon) && isset($polygon[0]) && is_array($polygon[0])) {
                    $outerRings[] = $polygon[0];
                }
            }
        }

        $cxSum = 0.0; $cySum = 0.0; $areaSum = 0.0;
        foreach ($outerRings as $ring) {
            [$cx, $cy, $area] = self::ringCentroidArea($ring);
            $cxSum += $cx * $area;
            $cySum += $cy * $area;
            $areaSum += $area;
        }
        if ($areaSum > 0.0) {
            return ['lon' => $cxSum / $areaSum, 'lat' => $cySum / $areaSum];
        }
        // Entartete Fläche → bbox-Mitte.
        $bb = self::bbox($geometry);
        if ($bb === null) {
            return null;
        }
        return ['lon' => ($bb['minLon'] + $bb['maxLon']) / 2, 'lat' => ($bb['minLat'] + $bb['maxLat']) / 2];
    }

    /**
     * Schwerpunkt (cx,cy) und Betragsfläche eines Rings (planar, Grad-Ebene).
     *
     * @param array<int,mixed> $ring
     * @return array{0:float,1:float,2:float}
     */
    private static function ringCentroidArea(array $ring): array
    {
        $n = count($ring);
        if ($n < 3) {
            return [0.0, 0.0, 0.0];
        }
        $a = 0.0; $cx = 0.0; $cy = 0.0;
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $pi = $ring[$i]; $pj = $ring[$j];
            if (!is_array($pi) || !is_array($pj)) {
                continue;
            }
            $xi = (float)$pi[0]; $yi = (float)$pi[1];
            $xj = (float)$pj[0]; $yj = (float)$pj[1];
            $cross = $xj * $yi - $xi * $yj;
            $a += $cross;
            $cx += ($xi + $xj) * $cross;
            $cy += ($yi + $yj) * $cross;
        }
        if (abs($a) < 1e-12) {
            return [0.0, 0.0, 0.0];
        }
        $area = $a / 2.0;
        return [$cx / (6.0 * $area), $cy / (6.0 * $area), abs($area)];
    }

    /**
     * Vereinfacht alle Ringe einer Geometrie per Douglas-Peucker mit Toleranz
     * `toleranceDeg` (Grad). Ringe, die dabei unter 4 Punkte fielen (ein
     * geschlossener Ring braucht ≥ 4), bleiben unverändert. Der Geometrietyp
     * bleibt erhalten.
     *
     * @param array<string,mixed> $geometry
     * @return array<string,mixed>
     */
    public static function simplify(array $geometry, float $toleranceDeg): array
    {
        if ($toleranceDeg <= 0) {
            return $geometry;
        }
        $type = $geometry['type'] ?? null;
        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords)) {
            return $geometry;
        }
        if ($type === 'Polygon') {
            $geometry['coordinates'] = self::simplifyRings($coords, $toleranceDeg);
        } elseif ($type === 'MultiPolygon') {
            $out = [];
            foreach ($coords as $polygon) {
                $out[] = is_array($polygon) ? self::simplifyRings($polygon, $toleranceDeg) : $polygon;
            }
            $geometry['coordinates'] = $out;
        }
        return $geometry;
    }

    /**
     * @param array<int,mixed> $rings
     * @return array<int,mixed>
     */
    private static function simplifyRings(array $rings, float $tol): array
    {
        $out = [];
        foreach ($rings as $ring) {
            if (!is_array($ring) || count($ring) < 5) {
                $out[] = $ring;
                continue;
            }
            $simplified = self::douglasPeucker($ring, $tol);
            // Ring geschlossen halten und Mindestpunktzahl sichern.
            if (count($simplified) < 4) {
                $out[] = $ring;
                continue;
            }
            $first = $simplified[0];
            $last = $simplified[count($simplified) - 1];
            if ($first !== $last) {
                $simplified[] = $first;
            }
            $out[] = $simplified;
        }
        return $out;
    }

    /**
     * Douglas-Peucker auf einer Punktfolge [[lon,lat], …].
     *
     * @param array<int,mixed> $points
     * @return array<int,mixed>
     */
    public static function douglasPeucker(array $points, float $tol): array
    {
        $n = count($points);
        if ($n < 3) {
            return $points;
        }
        $dmax = 0.0; $index = 0;
        $start = $points[0]; $end = $points[$n - 1];
        for ($i = 1; $i < $n - 1; $i++) {
            $d = self::perpDistance($points[$i], $start, $end);
            if ($d > $dmax) {
                $dmax = $d; $index = $i;
            }
        }
        if ($dmax > $tol) {
            $left = self::douglasPeucker(array_slice($points, 0, $index + 1), $tol);
            $right = self::douglasPeucker(array_slice($points, $index), $tol);
            return array_merge(array_slice($left, 0, count($left) - 1), $right);
        }
        return [$start, $end];
    }

    /**
     * @param array<int,mixed> $p
     * @param array<int,mixed> $a
     * @param array<int,mixed> $b
     */
    private static function perpDistance(array $p, array $a, array $b): float
    {
        $px = (float)$p[0]; $py = (float)$p[1];
        $ax = (float)$a[0]; $ay = (float)$a[1];
        $bx = (float)$b[0]; $by = (float)$b[1];
        $dx = $bx - $ax; $dy = $by - $ay;
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 < 1e-18) {
            $ex = $px - $ax; $ey = $py - $ay;
            return sqrt($ex * $ex + $ey * $ey);
        }
        $num = abs($dy * $px - $dx * $py + $bx * $ay - $by * $ax);
        return $num / sqrt($len2);
    }

    /**
     * Ruft `$fn(lon, lat)` für jede Koordinate einer Polygon/MultiPolygon-
     * Geometrie auf.
     *
     * @param array<string,mixed> $geometry
     */
    private static function eachCoord(array $geometry, callable $fn): void
    {
        $type = $geometry['type'] ?? null;
        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords)) {
            return;
        }
        if ($type === 'Polygon') {
            self::eachRingList($coords, $fn);
        } elseif ($type === 'MultiPolygon') {
            foreach ($coords as $polygon) {
                if (is_array($polygon)) {
                    self::eachRingList($polygon, $fn);
                }
            }
        }
    }

    /** @param array<int,mixed> $rings */
    private static function eachRingList(array $rings, callable $fn): void
    {
        foreach ($rings as $ring) {
            if (!is_array($ring)) {
                continue;
            }
            foreach ($ring as $pt) {
                if (is_array($pt) && isset($pt[0], $pt[1])) {
                    $fn((float)$pt[0], (float)$pt[1]);
                }
            }
        }
    }
}
