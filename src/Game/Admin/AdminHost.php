<?php
declare(strict_types=1);
namespace App\Game\Admin;

/** Entscheidet, ob ein Request-Host der Admin-Host ist. Rein + testbar. */
final class AdminHost
{
    /**
     * Erlaubte Admin-Hosts = explizit konfigurierte (`ADMIN_HOST`, kommasepariert)
     * ∪ die aus `APP_URL`/`PUBLIC_WEB_URL` abgeleiteten `admin.<host>`. Dadurch
     * funktioniert der Admin auf `admin.<app-domain>` auch dann, wenn `ADMIN_HOST`
     * noch auf einen alten Host zeigt — und mehrere Domains lassen sich vereinen
     * (z. B. Umzug grava.world → cyberride.world).
     */
    public static function isAdmin(
        string $requestHost,
        string $configuredAdminHost,
        string $appUrl,
        string $publicWebUrl = '',
    ): bool {
        $host = self::normalize($requestHost);
        if ($host === '') {
            return false;
        }

        $allowed = [];
        foreach (explode(',', $configuredAdminHost) as $candidate) {
            $h = self::normalize($candidate);
            if ($h !== '') {
                $allowed[$h] = true;
            }
        }
        foreach ([$appUrl, $publicWebUrl] as $url) {
            $base = self::normalize((string) (parse_url($url, PHP_URL_HOST) ?: $url));
            if ($base !== '') {
                $allowed['admin.' . ltrim($base, '.')] = true;
            }
        }

        return isset($allowed[$host]);
    }

    /** Kleinschreibung, Whitespace weg, Port abschneiden. */
    private static function normalize(string $value): string
    {
        return strtolower(trim(explode(':', trim($value))[0] ?? ''));
    }
}
