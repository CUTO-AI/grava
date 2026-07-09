<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Lokalisierter Ländername aus einem ISO-3166-1-alpha2-Code in der aktiven
 * Web-Sprache (de/en), damit z. B. asiatische/kyrillische Eigennamen lesbar
 * werden ("Россия" → "Russland"/"Russia"). Nutzt die intl-Extension; fehlt sie
 * oder ist der Code unbekannt, greift der übergebene Fallback (der native Name).
 */
final class CountryName
{
    public static function localized(?string $cc, ?string $fallback = null): string
    {
        $cc = $cc !== null ? strtoupper(trim($cc)) : '';
        if ($cc !== '' && preg_match('/^[A-Z]{2}$/', $cc) === 1 && extension_loaded('intl')) {
            $locale = I18n::locale();
            $locale = in_array($locale, ['de', 'en'], true) ? $locale : 'en';
            $name = \Locale::getDisplayRegion('-' . $cc, $locale);
            // Bei unbekanntem Code liefert ICU den Code selbst oder „Unbekannte
            // Region" — dann lieber den Fallback (nativer Name).
            if ($name !== '' && strtoupper($name) !== $cc && !str_contains($name, 'nbekannt') && !str_contains($name, 'nknown')) {
                return $name;
            }
        }
        return ($fallback !== null && $fallback !== '') ? $fallback : $cc;
    }
}
