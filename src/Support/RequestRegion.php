<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Bestimmt die Weltregion (Europa vs. Nordamerika) für einen anonymen
 * Web-Request, um die Startseiten-Karte passend vorzuzoomen.
 *
 * Entscheidungslogik (bewusst in dieser Reihenfolge):
 *  1. Geografie: Cloudflare setzt `CF-IPCountry` (ISO-3166-alpha-2) am Edge.
 *     Ist der Ländercode eindeutig EU oder NA, entscheidet er.
 *  2. Fallback (Geografie nicht ermittelbar ODER anderer Kontinent):
 *     Browsersprache. `en-US` → Nordamerika, alles andere → Europa.
 *
 * Kein GeoIP-Datenbank-Lookup nötig — CF-IPCountry kommt fertig vom CDN.
 * Fehlt der Header (kein Cloudflare davor), greift automatisch die
 * Sprachheuristik. Der Wert ist rein kosmetisch (Karten-Zoom), daher
 * unkritisch gegenüber Spoofing.
 */
final class RequestRegion
{
    /** Nordamerikanische Ländercodes, für die auf NA gezoomt wird. */
    private const NA_COUNTRIES = ['US', 'CA', 'MX'];

    /** Europäische Ländercodes, für die auf EU gezoomt wird. */
    private const EU_COUNTRIES = [
        'AL', 'AD', 'AT', 'BA', 'BE', 'BG', 'BY', 'CH', 'CY', 'CZ',
        'DE', 'DK', 'EE', 'ES', 'FI', 'FO', 'FR', 'GB', 'GE', 'GI',
        'GR', 'HR', 'HU', 'IE', 'IS', 'IT', 'LI', 'LT', 'LU', 'LV',
        'MC', 'MD', 'ME', 'MK', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO',
        'RS', 'RU', 'SE', 'SI', 'SK', 'SM', 'UA', 'VA', 'XK',
    ];

    /** Karten-Vorschau je Region: Mittelpunkt + Zoom (Kontinent-Übersicht). */
    private const VIEWS = [
        'eu' => ['lat' => 54.0, 'lon' => 15.0, 'zoom' => 4],
        'na' => ['lat' => 40.0, 'lon' => -97.0, 'zoom' => 4],
    ];

    /**
     * Löst die Kartenansicht aus den PHP-Superglobals auf.
     *
     * @return array{region:string,lat:float,lon:float,zoom:int}
     */
    public static function fromGlobals(): array
    {
        $cf   = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null;
        $lang = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        return self::resolve(is_string($cf) ? $cf : null, $lang);
    }

    /**
     * Pure-function-Variante (testbar, ohne Superglobals).
     *
     * @param string|null $cfCountry      Roh-Wert von `CF-IPCountry` (oder null)
     * @param string      $acceptLanguage Roh-Wert von `Accept-Language`
     * @return array{region:string,lat:float,lon:float,zoom:int}
     */
    public static function resolve(?string $cfCountry, string $acceptLanguage): array
    {
        $region = self::detectRegion($cfCountry, $acceptLanguage);
        $view   = self::VIEWS[$region];
        return ['region' => $region] + $view;
    }

    /** Liefert 'eu' oder 'na'. */
    private static function detectRegion(?string $cfCountry, string $acceptLanguage): string
    {
        // 1) Geografie (Cloudflare-Edge). "XX"/"T1" = unbekannt/Tor → durchfallen.
        $cc = strtoupper(trim((string) $cfCountry));
        if ($cc !== '' && !in_array($cc, ['XX', 'T1', 'ZZ'], true)) {
            if (in_array($cc, self::NA_COUNTRIES, true)) {
                return 'na';
            }
            if (in_array($cc, self::EU_COUNTRIES, true)) {
                return 'eu';
            }
            // Anderer Kontinent (z. B. Asien): bewusst auf Sprachheuristik.
        }

        // 2) Fallback: Browsersprache. Höchstpriorisiertes Tag ohne q-Wert.
        $primary = strtolower(trim(explode(',', $acceptLanguage)[0] ?? ''));
        $primary = trim(explode(';', $primary)[0] ?? '');
        return $primary === 'en-us' ? 'na' : 'eu';
    }
}
