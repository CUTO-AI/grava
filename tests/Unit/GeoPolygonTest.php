<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\GeoPolygon;
use PHPUnit\Framework\TestCase;

final class GeoPolygonTest extends TestCase
{
    /** Ein Einheitsquadrat (lon 0..1, lat 0..1) als GeoJSON-Polygon. */
    private function square(): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]]],
        ];
    }

    public function testContainsInsideAndOutside(): void
    {
        $sq = $this->square();
        // contains(lat, lon)
        $this->assertTrue(GeoPolygon::contains(0.5, 0.5, $sq));
        $this->assertFalse(GeoPolygon::contains(1.5, 0.5, $sq));
        $this->assertFalse(GeoPolygon::contains(0.5, -0.1, $sq));
    }

    public function testHoleIsExcluded(): void
    {
        $withHole = [
            'type' => 'Polygon',
            'coordinates' => [
                [[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0], [0.0, 0.0]],     // outer
                [[4.0, 4.0], [6.0, 4.0], [6.0, 6.0], [4.0, 6.0], [4.0, 4.0]],         // hole
            ],
        ];
        $this->assertTrue(GeoPolygon::contains(1.0, 1.0, $withHole));   // im Ring, nicht im Loch
        $this->assertFalse(GeoPolygon::contains(5.0, 5.0, $withHole));  // im Loch
    }

    public function testMultiPolygonAnyPart(): void
    {
        $multi = [
            'type' => 'MultiPolygon',
            'coordinates' => [
                [[[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]]],
                [[[5.0, 5.0], [6.0, 5.0], [6.0, 6.0], [5.0, 6.0], [5.0, 5.0]]],
            ],
        ];
        $this->assertTrue(GeoPolygon::contains(0.5, 0.5, $multi));
        $this->assertTrue(GeoPolygon::contains(5.5, 5.5, $multi));
        $this->assertFalse(GeoPolygon::contains(3.0, 3.0, $multi));
    }

    public function testBbox(): void
    {
        $bb = GeoPolygon::bbox($this->square());
        $this->assertNotNull($bb);
        $this->assertSame(0.0, $bb['minLat']);
        $this->assertSame(0.0, $bb['minLon']);
        $this->assertSame(1.0, $bb['maxLat']);
        $this->assertSame(1.0, $bb['maxLon']);
    }

    public function testRepresentativePointIsCentroidForSquare(): void
    {
        $pt = GeoPolygon::representativePoint($this->square());
        $this->assertNotNull($pt);
        $this->assertEqualsWithDelta(0.5, $pt['lat'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $pt['lon'], 1e-9);
        // Der Anker liegt im Polygon.
        $this->assertTrue(GeoPolygon::contains($pt['lat'], $pt['lon'], $this->square()));
    }

    public function testSimplifyDropsCollinearPointsButKeepsShape(): void
    {
        // Rechteck mit vielen kollinearen Zwischenpunkten auf der Unterkante.
        $ring = [[0.0, 0.0], [0.25, 0.0], [0.5, 0.0], [0.75, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0], [0.0, 0.0]];
        $geom = ['type' => 'Polygon', 'coordinates' => [$ring]];
        $simpl = GeoPolygon::simplify($geom, 0.001);
        $out = $simpl['coordinates'][0];
        // Kollineare Punkte entfernt → deutlich weniger, aber geschlossen.
        $this->assertLessThan(count($ring), count($out));
        $this->assertSame($out[0], $out[count($out) - 1]);
        // Enthält weiterhin denselben Innenpunkt.
        $this->assertTrue(GeoPolygon::contains(0.5, 0.5, $simpl));
    }

    public function testDegenerateGeometryReturnsSafely(): void
    {
        $this->assertFalse(GeoPolygon::contains(0.0, 0.0, ['type' => 'Polygon', 'coordinates' => 'nope']));
        $this->assertNull(GeoPolygon::bbox(['type' => 'Polygon', 'coordinates' => []]));
    }
}
