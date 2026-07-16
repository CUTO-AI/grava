<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Game\GameRepository;
use App\Game\RegionService;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Support\I18n;

/**
 * Öffentliche Gebiets-Auswertung (WebAnalytics_Concept.md, Phase C): Einstieg
 * über die Länderliste (/gebiete) und Drilldown ins Detail (/gebiete/{id}) mit
 * Kennzahlen, In-Gebiet-Bestenliste, Breadcrumb (hoch) und Unter-Gebieten
 * (runter). Server-seitig gerendert; nutzt {@see RegionService}. Reine Listen.
 */
final class RegionsPagesController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly RegionService $regions,
        private readonly GameRepository $game,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        [$user, $viewerClaimant] = $this->resolveViewer();
        $this->regions->setLanguage(I18n::locale());
        $regions = $this->regions->rootRegions($viewerClaimant)['regions'];

        $this->view->render('regions/index', [
            '_title'      => t('Gebiete') . ' · CYBERRIDE',
            '_authedUser' => $user,
            '_pageStyles' => ['/assets/css/analytics.css'],
            '_layoutWide' => true,
            'regions'     => $regions,
        ]);
    }

    public function map(Request $req): void
    {
        [$user] = $this->resolveViewer();
        $this->view->render('regions/map', [
            '_title'       => t('Gebiets-Karte') . ' · CYBERRIDE',
            '_authedUser'  => $user,
            '_pageStyles'  => ['/assets/vendor/leaflet/leaflet.css', '/assets/css/regions-map.css'],
            '_pageScripts' => [
                '/assets/vendor/leaflet/leaflet.js',
                '/assets/js/map-core.js',
                '/assets/js/map-regions.js',
            ],
            '_layoutWide'  => true,
        ]);
    }

    public function detail(Request $req, int $id): void
    {
        [$user, $viewerClaimant] = $this->resolveViewer();
        $this->regions->setLanguage(I18n::locale());
        $detail = $id > 0 ? $this->regions->regionDetail($id, $viewerClaimant) : null;
        if ($detail === null) {
            Response::redirect('/gebiete');
        }

        $this->view->render('regions/detail', [
            '_title'      => $detail['name'] . ' · ' . t('Gebiete') . ' · CYBERRIDE',
            '_authedUser' => $user,
            '_pageStyles' => ['/assets/css/analytics.css'],
            '_layoutWide' => true,
            'region'      => $detail,
        ]);
    }

    /**
     * Löst den optionalen Web-Nutzer + dessen Solo-Claimant auf (für „mein
     * Gebiet"-Markierungen). Anonym → [null, null].
     *
     * @return array{0:array<string,mixed>|null,1:?int}
     */
    private function resolveViewer(): array
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            return [null, null];
        }
        Csrf::ensureStarted();
        $user = $this->auth->loadUserPublic($ctx['user_id']);
        // Lese-Pfad: KEINEN Claimant anlegen, nur nachschlagen.
        $claimant = $this->game->findRiderClaimantId((int)$ctx['user_id']);
        return [$user, $claimant];
    }
}
