<?php
declare(strict_types=1);

namespace App\Support;

use App\Config\Config;

/**
 * Öffentliche Basis-URL + absolute Links für die nutzerseitigen Web-Seiten.
 *
 * Bündelt das bisher mehrfach duplizierte PUBLIC_WEB_URL→APP_URL-Fallback
 * (siehe AuthService::publicWebBase, ReferralService, RoutePagesController).
 * Für SEO wichtig: canonical/og:url sollen IMMER auf die Marken-Domain
 * (cyberride.world) zeigen — unabhängig davon, über welchen Host der Request
 * kam — damit grava.world/cyberride.world nicht als Duplicate-Content zählen.
 */
final class SiteUrl
{
    /** Öffentliche Basis-URL ohne abschließenden Slash, z. B. https://cyberride.world. */
    public static function base(): string
    {
        $base = (string) Config::instance()->get('PUBLIC_WEB_URL', '');
        if ($base === '') {
            $base = (string) Config::instance()->get('APP_URL', '');
        }
        return rtrim($base, '/');
    }

    /**
     * Absolute URL für einen Pfad. Der Pfad sollte ohne Query-String übergeben
     * werden (Canonical konsolidiert Filter-/Theme-/Sprach-Parameter). Ist keine
     * Basis konfiguriert, wird der Pfad unverändert (relativ) zurückgegeben.
     */
    public static function absolute(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return self::base() . $path;
    }

    // --- Zweisprachigkeit (SEO) --------------------------------------------
    // Sprache läuft über ?lang= + Cookie (I18n), Default ist Englisch. Für SEO
    // gilt: Default (EN) = paramlose URL, DE = ?lang=de. So ist jede Sprach-URL
    // eindeutig crawlbar und kann sich selbst kanonisieren.
    private const DEFAULT_LANG = 'en';

    /** Absolute, sprach-spezifische URL: paramlos für Default (en), ?lang=de sonst. */
    public static function localized(string $path, string $lang): string
    {
        $url = self::absolute($path);
        if ($lang !== self::DEFAULT_LANG) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'lang=' . rawurlencode($lang);
        }
        return $url;
    }

    /**
     * hreflang-`<link>`-Tags (de, en, x-default) für einen Pfad.
     * Wird im <head> ausgegeben; erlaubt Google, die passende Sprachfassung
     * auszuliefern. x-default zeigt auf die Default-Fassung (paramlos/EN).
     */
    public static function hreflangLinks(string $path): string
    {
        $en = self::localized($path, 'en');
        $de = self::localized($path, 'de');
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<link rel="alternate" hreflang="de" href="' . $esc($de) . '">'
             . '<link rel="alternate" hreflang="en" href="' . $esc($en) . '">'
             . '<link rel="alternate" hreflang="x-default" href="' . $esc($en) . '">';
    }
}
