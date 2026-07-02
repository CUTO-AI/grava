<?php
namespace App\Controllers\Web;

/**
 * Landing-Page Controller (DEV)
 *
 * Temporärer Controller für die neue Landing-Page während der Entwicklung.
 * Finale Integration: → MarketingController, Route / statt /landing
 */
class LandingController
{
    private readonly WebView $view;

    public function __construct(?string $viewsPath = null)
    {
        $viewsPath = $viewsPath ?? dirname(__DIR__, 3) . '/views';
        $this->view = new WebView($viewsPath);
    }

    public function home(): never
    {
        // --- Parallelbetrieb Alt/Cyber-Design ---------------------------------
        // Opt-in per ?theme=cyber (setzt 30-Tage-Cookie); ?theme=classic zurück.
        // Solange nur die Landing migriert ist, greift der Schalter genau hier.
        if ($this->wantsCyberTheme()) {
            $this->renderCyberLanding();
        }

        // Hole aktuelle öffentliche Fahrten für die Gallery
        $recentRoutes = $this->getRecentPublicRoutes(10);

        $this->view->render('landing/home', [
            '_title' => 'GRAVA — Oberfläche, Verkehr & Hinweise: Community-Map für Radfahrer',
            '_authedUser' => null, // Anonymer Besucher
            '_pageStyles' => [
                'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
                '/assets/landing/landing.css'
            ],
            '_pageScripts' => [
                'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
                '/assets/js/landing-map.js',
                '/assets/js/landing-gallery.js'
            ],
            '_layoutWide' => true,
            'recentRoutes' => $recentRoutes,

            // SEO Meta-Tags
            '_metaDescription' => 'GRAVA misst automatisch Oberflächenqualität und Verkehr (Radarlicht). Speichere Hinweise wie Blockierungen oder Gefahrenstellen. Sammle dein Gebiet, hilf der Community die Datenbank aufzubauen. Die Daten, die in Komoot fehlen.',
            '_metaKeywords' => 'Gravel, Bikepacking, Radfahren, Oberflächenqualität, Straßenbelag, Schotter, Verkehr, Radarlicht, Community-Hinweise, GPS-Tracking, Gamification, Territorialspiel, Rennrad, MTB, Komoot Alternative',
            '_ogTitle' => 'GRAVA — Oberfläche, Verkehr & Community-Hinweise für Radfahrer',
            '_ogDescription' => 'Automatisch per Radarlicht: Oberfläche & Verkehr. Manuell: Hinweise für alle. Erobere dein Gebiet, baue die Map auf. Launch-Phase — sei dabei!',
            '_ogImage' => '/assets/landing/screenshot-game-map.webp',
            '_ogUrl' => '/landing',
        ]);
    }

    /**
     * Löst den Theme-Wunsch auf. ?theme=… überschreibt und persistiert das
     * Cookie; ohne Query zählt das zuvor gesetzte Cookie. Default: classic.
     */
    private function wantsCyberTheme(): bool
    {
        $theme = $_GET['theme'] ?? ($_COOKIE['theme'] ?? 'classic');
        $theme = $theme === 'cyber' ? 'cyber' : 'classic';

        if (isset($_GET['theme'])) {
            $secure = (($_SERVER['HTTPS'] ?? '') !== '')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie('theme', $theme, [
                'expires'  => time() + 2592000, // 30 Tage
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => false,            // rein kosmetisch, kein Secret
                'samesite' => 'Lax',
            ]);
            $_COOKIE['theme'] = $theme;
        }

        return $theme === 'cyber';
    }

    /**
     * Rendert die eigenständige Cyber-Landing (public/cyber/index.php) mit
     * eigenem Layout/Assets. Bricht die Anfrage danach ab.
     */
    private function renderCyberLanding(): void
    {
        $entry = dirname(__DIR__, 3) . '/public/cyber/index.php';
        if (is_file($entry)) {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            $CR_ASSETS = '/cyber/assets';
            require $entry;
            exit;
        }
        // Fallback: fehlt das Bundle, normal weiter mit der klassischen Seite.
    }

    private function getRecentPublicRoutes(int $limit): array
    {
        try {
            $config = \App\Config\Config::instance();
            $dsn = 'mysql:host=' . $config->get('DB_HOST') . ';dbname=' . $config->get('DB_NAME') . ';charset=utf8mb4';
            $pdo = new \PDO($dsn, $config->get('DB_USER'), $config->get('DB_PASS'));
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("
                SELECT
                    r.id,
                    r.title,
                    r.distance_m,
                    r.created_at,
                    u.public_handle as handle
                FROM routes r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE r.visibility = 'public'
                ORDER BY r.created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            // Bei Fehler: leeres Array zurückgeben statt Fehler zu werfen
            error_log("Failed to fetch recent routes: " . $e->getMessage());
            return [];
        }
    }
}
