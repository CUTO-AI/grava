<?php
declare(strict_types=1);

namespace Tests\Unit\Integrations;

use App\Integrations\Wahoo\FitDecoder;
use App\Integrations\Wahoo\WahooException;
use PHPUnit\Framework\TestCase;

/**
 * Wahoo-Integration Phase C: FIT-Decoder gegen echte Fixtures.
 *  - ride.fit   = ELEMNT-ROAM-Aktivität (GPS + Sensoren)
 *  - sample.fit = Trainings-/Workout-Definition (kein GPS → Skip)
 */
final class FitDecoderTest extends TestCase
{
    private FitDecoder $decoder;
    private string $fixtures;

    protected function setUp(): void
    {
        $this->decoder = new FitDecoder();
        $this->fixtures = \dirname(__DIR__, 2) . '/fixtures/wahoo';
    }

    private function bytes(string $name): string
    {
        // Fixtures sind NICHT eingecheckt (enthalten echte GPS-/Fitnessdaten,
        // Datenschutz). Lokal vorhanden → Test läuft; in CI → übersprungen.
        $path = $this->fixtures . '/' . $name;
        if (!is_file($path)) {
            $this->markTestSkipped("FIT-Fixture nicht vorhanden (nur lokal): $name");
        }
        return (string)file_get_contents($path);
    }

    public function testDecodesActivityRideToGeoJson(): void
    {
        $res = $this->decoder->decode($this->bytes('ride.fit'));

        // GPS-Track vorhanden (ELEMNT-ROAM: ~7071 Punkte).
        $this->assertGreaterThan(7000, $res['point_count']);

        // Startzeit aufs Fahrdatum (Dateiname 2026-04-17).
        $this->assertNotNull($res['started_at']);
        $this->assertStringStartsWith('2026-04-17', (string)$res['started_at']);

        // Gültiges GeoJSON-LineString-Feature mit startedAt-Property.
        $gj = json_decode($res['geojson'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Feature', $gj['type']);
        $this->assertSame('LineString', $gj['geometry']['type']);
        $this->assertSame($res['point_count'], count($gj['geometry']['coordinates']));
        $this->assertStringStartsWith('2026-04-17', (string)$gj['properties']['startedAt']);

        // Koordinaten als [lon, lat, (alt)] in Bayern (~12.41 / 48.20).
        [$lon, $lat] = $gj['geometry']['coordinates'][0];
        $this->assertEqualsWithDelta(12.41, $lon, 0.1);
        $this->assertEqualsWithDelta(48.20, $lat, 0.1);
    }

    public function testExtractsSensorAggregates(): void
    {
        $a = $this->decoder->decode($this->bytes('ride.fit'))['aggregates'];
        $this->assertSame(126, $a['avg_power_w']);
        $this->assertSame(728, $a['max_power_w']);
        $this->assertSame(72, $a['avg_cadence_rpm']);
        $this->assertSame(127, $a['avg_heart_rate_bpm']);
        $this->assertSame(153, $a['max_heart_rate_bpm']);
    }

    public function testWorkoutDefinitionHasNoTrack(): void
    {
        // Kein Activity-FIT (file_id.type=3, keine record-Messages) → kein Track.
        $res = $this->decoder->decode($this->bytes('sample.fit'));
        $this->assertSame(0, $res['point_count']);
    }

    public function testEmptyBytesThrow(): void
    {
        $this->expectException(WahooException::class);
        $this->decoder->decode('');
    }
}
