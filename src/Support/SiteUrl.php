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
}
