<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Database\Db;
use App\Support\SiteUrl;
use PDO;
use Throwable;

/**
 * Dynamische sitemap.xml für Suchmaschinen. Enthält NUR öffentliche,
 * indexierbare URLs — passend zur noindex-Policy in WebView:
 *
 *  - statische Marketing-/Discovery-/Legal-Seiten,
 *  - öffentliche Profile (users.public_handle IS NOT NULL, status='active'),
 *  - öffentliche Routen (routes.visibility='public', nicht gelöscht) unter
 *    der Handle-URL ihres Besitzers.
 *
 * Ausgeschlossen: Auth, persönlicher App-Bereich, tokenisierte Shares, Admin.
 * Sprach-Alternates (hreflang/xhtml:link) folgen in Phase E.
 */
final class SitemapController
{
    /** Kappungsgrenze je dynamischem Block (Sitemap-Limit ist 50k URLs gesamt). */
    private const MAX_PER_TYPE = 20000;

    /** @var list<string> Öffentliche, indexierbare statische Pfade. */
    private const STATIC_PATHS = [
        '/', '/features', '/pulse', '/discover', '/discover/users',
        '/heatmap', '/privacy', '/terms', '/imprint',
    ];

    public function show(): never
    {
        header('Content-Type: application/xml; charset=utf-8');

        $base = SiteUrl::base();
        $out  = [];
        $out[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $out[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach (self::STATIC_PATHS as $path) {
            $out[] = self::urlEntry($base . $path, null);
        }

        // Dynamische Einträge sind best-effort: bei DB-Problemen liefern wir
        // trotzdem eine valide Sitemap mit den statischen URLs aus.
        try {
            $pdo = Db::pdo();

            $profiles = $pdo->query(
                "SELECT public_handle, updated_at
                   FROM users
                  WHERE public_handle IS NOT NULL
                    AND status = 'active'
                  ORDER BY updated_at DESC
                  LIMIT " . self::MAX_PER_TYPE
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($profiles as $p) {
                $handle = (string)$p['public_handle'];
                $out[] = self::urlEntry(
                    $base . '/u/' . rawurlencode($handle),
                    self::w3c($p['updated_at'] ?? null)
                );
            }

            $routes = $pdo->query(
                "SELECT r.public_id, r.updated_at, u.public_handle
                   FROM routes r
                   JOIN users u ON u.id = r.user_id
                  WHERE r.visibility = 'public'
                    AND r.deleted_at IS NULL
                    AND u.public_handle IS NOT NULL
                    AND u.status = 'active'
                  ORDER BY r.updated_at DESC
                  LIMIT " . self::MAX_PER_TYPE
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($routes as $r) {
                $handle = (string)$r['public_handle'];
                // Öffentliche Routen-URL nutzt die public_id (UUID), NICHT die
                // numerische PK — sonst 404.
                $out[] = self::urlEntry(
                    $base . '/u/' . rawurlencode($handle) . '/r/' . rawurlencode((string)$r['public_id']),
                    self::w3c($r['updated_at'] ?? null)
                );
            }
        } catch (Throwable $e) {
            error_log('sitemap: dynamic entries skipped — ' . $e->getMessage());
        }

        $out[] = '</urlset>';
        echo implode("\n", $out) . "\n";
        exit;
    }

    private static function urlEntry(string $loc, ?string $lastmod): string
    {
        $xml = '  <url><loc>' . htmlspecialchars($loc, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</loc>';
        if ($lastmod !== null) {
            $xml .= '<lastmod>' . $lastmod . '</lastmod>';
        }
        return $xml . '</url>';
    }

    /** DATETIME (UTC) → W3C-Datum (YYYY-MM-DD). Ungültig/leer → null. */
    private static function w3c(?string $datetime): ?string
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        $ts = strtotime($datetime . ' UTC');
        return $ts !== false ? gmdate('Y-m-d', $ts) : null;
    }
}
