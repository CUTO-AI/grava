<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CountryName;
use PHPUnit\Framework\TestCase;

/**
 * Lokalisierte Ländernamen aus ISO-3166-1-alpha2. Die Lokalisierung braucht eine
 * wirksame intl/ICU-Umgebung; fehlt sie (manche CI-Runner), werden nur die
 * intl-unabhängigen Fallback-Pfade geprüft.
 */
final class CountryNameTest extends TestCase
{
    private function intlEffective(): bool
    {
        // In produktiven Umgebungen lokalisiert intl; wenn nicht, kommt der Code zurück.
        return extension_loaded('intl') && CountryName::localized('RU') === 'Russia';
    }

    public function testKnownCodesLocalizeToWebLanguage(): void
    {
        if (!$this->intlEffective()) {
            $this->markTestSkipped('intl-Lokalisierung in dieser Umgebung nicht wirksam');
        }
        $this->assertSame('Russia', CountryName::localized('RU'));
        $this->assertSame('Japan', CountryName::localized('JP'));
        $this->assertSame('France', CountryName::localized(' fr '));   // Trim + Case
    }

    public function testNullOrUnknownFallsBackToNativeName(): void
    {
        // intl-unabhängig: ohne/mit ungültigem Code gewinnt der native Name.
        $this->assertSame('Россия', CountryName::localized(null, 'Россия'));
        $this->assertSame('España (mar territorial)', CountryName::localized('', 'España (mar territorial)'));
        $this->assertSame('Freistaat', CountryName::localized('ZZZ', 'Freistaat'));
    }
}
