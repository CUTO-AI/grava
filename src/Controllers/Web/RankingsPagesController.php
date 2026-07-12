<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Game\CrewLeaderboardService;
use App\Game\Faction\FactionService;
use App\Game\GameRepository;
use App\Http\Middleware\Csrf;
use App\Http\Request;

/**
 * Öffentliche Ranglisten-Seiten (WebAnalytics_Concept.md, Phase B): Solo, Crews
 * und Fraktionen als all-time-Auswertung nach gehaltenem Revier. Server-seitig
 * gerendert (SEO-indexierbar), Tabs teilen sich eine View. Nur die Daten des
 * aktiven Tabs werden geladen.
 */
final class RankingsPagesController
{
    private const TABS = ['solo', 'crews', 'fraktionen'];

    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly GameRepository $game,
        private readonly CrewLeaderboardService $crewBoard,
        private readonly FactionService $factions,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function show(Request $req, string $tab = 'solo'): void
    {
        $tab = in_array($tab, self::TABS, true) ? $tab : 'solo';

        $user = null;
        $ctx = $this->webSession->resolve();
        if ($ctx !== null) {
            $user = $this->auth->loadUserPublic($ctx['user_id']);
            Csrf::ensureStarted();
        }

        $solo = $crews = $facts = null;
        $titleTab = t('Solo');
        switch ($tab) {
            case 'crews':
                $crews = $this->crewBoard->leaderboard()['entries'];
                $titleTab = t('Crews');
                break;
            case 'fraktionen':
                $facts = $this->factions->standings()['factions'];
                $titleTab = t('Fraktionen');
                break;
            default:
                $solo = $this->game->topRidersByHeldLength(100);
                $titleTab = t('Solo');
        }

        $this->view->render('rankings', [
            '_title'       => $titleTab . ' · ' . t('Ranglisten') . ' · CYBERRIDE',
            '_authedUser'  => $user,
            '_pageStyles'  => ['/assets/css/analytics.css'],
            '_layoutWide'  => true,
            'tab'          => $tab,
            'solo'         => $solo,
            'crews'        => $crews,
            'factions'     => $facts,
        ]);
    }

    /**
     * „Über Karte"-Tab der Ranglisten (UserGrowth_Concept.md §4): dieselbe
     * interaktive Gebiets-Karte wie die Startseite; Klick auf ein Gebiet zeigt die
     * windowed Nordstern-Aktivität (Gesamt/Solo/Crews, 7/30 Tage). Client-seitig
     * same-origin aus /api/v1/game/regions[/{id}][/activity] (map-regions.js,
     * data-activity="1").
     */
    public function map(Request $req): void
    {
        $user = null;
        $ctx = $this->webSession->resolve();
        if ($ctx !== null) {
            $user = $this->auth->loadUserPublic($ctx['user_id']);
            Csrf::ensureStarted();
        }

        $this->view->render('rankings_map', [
            '_title'       => t('Über Karte') . ' · ' . t('Ranglisten') . ' · CYBERRIDE',
            '_authedUser'  => $user,
            '_pageStyles'  => ['/assets/vendor/leaflet/leaflet.css', '/assets/css/regions-map.css', '/assets/css/analytics.css'],
            '_pageScripts' => [
                '/assets/vendor/leaflet/leaflet.js',
                '/assets/js/map-core.js',
                '/assets/js/map-regions.js',
            ],
            '_layoutWide'  => true,
        ]);
    }
}
