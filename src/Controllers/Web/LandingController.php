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
        // Startseite = zweisprachige Cyber-Landing (public/cyber). Die Sprache
        // (EN Standard, ?lang=de/en + Cookie) wird in public/cyber/inc/lang.php
        // aufgelöst. Eigenständiges Layout/Assets — bricht danach ab.
        $entry = dirname(__DIR__, 3) . '/public/cyber/index.php';
        if (is_file($entry)) {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
            $CR_ASSETS = '/cyber/assets';
            require $entry;
            exit;
        }

        // Fallback (sollte nicht eintreten): alte klassische Landing.
        $this->view->render('landing/home', [
            '_title'         => 'GRAVA',
            '_authedUser'    => null,
            '_classicLayout' => true,
            '_pageStyles'    => ['/assets/landing/landing.css'],
            'recentRoutes'   => $this->getRecentPublicRoutes(10),
        ]);
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
