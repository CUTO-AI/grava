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
}
