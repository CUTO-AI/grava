<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Http\Middleware\Csrf;

/**
 * Gemeinsamer Render-Pfad für die Web-Controller. Vorher dupliziert
 * in AuthPagesController::render und DashboardController::show — jetzt
 * an einer Stelle.
 *
 * Erwartet eine `views/`-Wurzel, in der die Pfade
 *   - views/web/{view}.php  (Inhalts-Partial)
 *   - views/web/layout.php  (Rahmenseite)
 * existieren. Die Layout-Seite bekommt `$content` (HTML aus dem Partial),
 * `$_title`, `$_view` und `$_csrf` — sowie alle übergebenen $vars.
 */
final class WebView
{
    public function __construct(private readonly string $viewsPath) {}

    /**
     * @param array<string,mixed> $vars
     */
    public function render(string $view, array $vars = [], int $status = 200): never
    {
        Csrf::ensureStarted();
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');

        $vars['_csrf']  = Csrf::token();
        $vars['_title'] = $vars['_title'] ?? ucfirst($view) . ' · CYBERRIDE';
        $vars['_view']  = $view;

        // --- SEO: Canonical + Robots zentral setzen -------------------------
        // Canonical = absolute Marken-URL OHNE Query-String (konsolidiert
        // ?theme=… / ?lang=… / Filter-Parameter zu einer indexierbaren URL).
        // og:url erbt den Canonical. Robots wird pfadbasiert differenziert:
        // private/dünne Bereiche (Auth, Dashboard, eigene Routen, Token-Shares
        // …) bekommen noindex. Controller können jeden Wert explizit überschreiben.
        $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
        $path = ($path === false || $path === '') ? '/' : $path;
        $canonical = \App\Support\SiteUrl::absolute($path);
        $vars['_canonical'] = $vars['_canonical'] ?? $canonical;
        $vars['_ogUrl']     = $vars['_ogUrl']     ?? $canonical;
        $vars['_robots']    = $vars['_robots']    ?? (self::isNoindexPath($path) ? 'noindex, follow' : 'index, follow');

        $base    = rtrim($this->viewsPath, '/') . '/web/';
        $partial = $base . $view . '.php';
        $layout  = $base . 'layout.php';

        // --- Cyber-Design ist Standard --------------------------------------
        // Jede View rendert in der Cyber-Schale (layout_cyber.php); das
        // vorhandene Markup wird per app.css global umgeskinnt. Existiert eine
        // handpolierte Variante views/web/cyber/<view>.php, wird diese bevorzugt.
        // Ausnahmen: Views mit '_classicLayout' => true (z. B. Marketing-Landing
        // mit eigenem Design) und die Escape-Hatch ?theme=classic bleiben alt.
        $forceClassic = !empty($vars['_classicLayout']);
        if (!$forceClassic && \App\Support\Theme::wantsCyber()) {
            $cyberLayout  = $base . 'layout_cyber.php';
            if (is_file($cyberLayout)) {
                $layout       = $cyberLayout;
                $cyberPartial = $base . 'cyber/' . $view . '.php';
                if (is_file($cyberPartial)) {
                    $partial = $cyberPartial;
                }
            }
        }

        extract($vars, EXTR_SKIP);

        ob_start();
        include $partial;
        $content = (string)ob_get_clean();

        include $layout;
        exit;
    }

    /**
     * Pfade, die NICHT indexiert werden sollen: Auth-Formulare, persönlicher
     * App-Bereich und tokenisierte/geteilte Ansichten. Öffentliche Inhalte
     * (Landing, /features, /pulse, /discover, /heatmap, /u/…, Legal, /i/…)
     * bleiben index,follow. Präfix-Match, damit Unterpfade mitgreifen.
     */
    private static function isNoindexPath(string $path): bool
    {
        $noindex = [
            '/login', '/register', '/forgot-password', '/reset-password',
            '/verify-email', '/dashboard', '/routes', '/settings',
            '/feed', '/notifications', '/surface-check', '/share/', '/auth/',
        ];
        foreach ($noindex as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
