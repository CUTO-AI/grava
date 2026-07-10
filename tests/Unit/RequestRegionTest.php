<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RequestRegion;
use PHPUnit\Framework\TestCase;

final class RequestRegionTest extends TestCase
{
    public function testGeographyEuropeWins(): void
    {
        $v = RequestRegion::resolve('DE', 'en-US,en;q=0.9');
        $this->assertSame('eu', $v['region']);
        $this->assertSame(4, $v['zoom']);
    }

    public function testGeographyNorthAmericaWins(): void
    {
        // Geografie hat Vorrang vor der Sprache (hier deutsche Sprache, US-IP).
        $v = RequestRegion::resolve('US', 'de-DE,de;q=0.9');
        $this->assertSame('na', $v['region']);
        $this->assertEqualsWithDelta(-97.0, $v['lon'], 0.001);
    }

    public function testCanadaAndMexicoAreNorthAmerica(): void
    {
        $this->assertSame('na', RequestRegion::resolve('CA', '')['region']);
        $this->assertSame('na', RequestRegion::resolve('MX', '')['region']);
    }

    public function testOtherContinentFallsBackToLanguage(): void
    {
        // Japan: weder EU noch NA → Sprachheuristik entscheidet.
        $this->assertSame('na', RequestRegion::resolve('JP', 'en-US,en;q=0.9')['region']);
        $this->assertSame('eu', RequestRegion::resolve('JP', 'ja-JP,ja;q=0.9')['region']);
    }

    public function testUnknownCountryCodeFallsBackToLanguage(): void
    {
        $this->assertSame('na', RequestRegion::resolve('XX', 'en-US')['region']);
        $this->assertSame('eu', RequestRegion::resolve('T1', 'fr-FR')['region']);
    }

    public function testNoGeographyUsesLanguageEnUsIsNorthAmerica(): void
    {
        $this->assertSame('na', RequestRegion::resolve(null, 'en-US,en;q=0.9')['region']);
    }

    public function testNoGeographyOtherLanguageIsEurope(): void
    {
        $this->assertSame('eu', RequestRegion::resolve(null, 'en-GB,en;q=0.8')['region']);
        $this->assertSame('eu', RequestRegion::resolve(null, 'de-DE')['region']);
        $this->assertSame('eu', RequestRegion::resolve(null, '')['region']);
    }

    public function testEnUsDetectionIsCaseInsensitive(): void
    {
        $this->assertSame('na', RequestRegion::resolve(null, 'EN-US')['region']);
    }
}
