<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Config\Config;
use App\Game\Crew\CrewService;
use App\Http\Request;
use App\Http\Response;

/**
 * Öffentliche Crew-Einladungs-Landingpage `GET /c/{code}`.
 *
 * iOS fängt /c/{code} als Universal Link ab (siehe AASA) und öffnet die App
 * direkt („Tritt Verein X bei"). Ist die App nicht installiert, landet der
 * Browser hier: wir zeigen — sofern der Code gültig ist — Name/Mitgliederzahl
 * der Crew, verlinken den App-Store und den „In der App öffnen"-Link.
 *
 * Kein Login nötig. Einladungscodes sind vom Verein bewusst öffentlich geteilt,
 * daher ist das Auflösen des Crew-Namens unkritisch; ungültige Codes rendern
 * eine neutrale Variante.
 */
final class CrewPagesController
{
    private readonly WebView $view;

    public function __construct(
        private readonly Config $config,
        private readonly CrewService $crews,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function landing(Request $req): void
    {
        $raw = (string)($req->routeParams['code'] ?? '');
        // Nur das erlaubte Join-Code-Format durchlassen.
        if (preg_match('/^[a-z0-9]{1,16}$/i', $raw) !== 1) {
            Response::redirect('/');
        }
        $code = strtoupper($raw);

        $crew = $this->crews->profileByJoinCode($code);

        $this->view->render('crew/landing', [
            '_title'        => 'Vereins-Einladung · CYBERRIDE',
            'join_code'     => $code,
            'crew_name'     => $crew !== null ? (string)$crew['name'] : null,
            'crew_slug'     => $crew !== null ? (string)$crew['slug'] : null,
            'member_count'  => $crew !== null ? (int)$crew['member_count'] : null,
            'has_logo'      => $crew !== null && ($crew['logo_updated_at'] ?? null) !== null,
            'open_url'      => 'https://cyberride.world/c/' . rawurlencode($code),
            'app_store_url' => (string)$this->config->get('APP_STORE_URL', ''),
        ]);
    }

    /**
     * Web-Fallback für den Vereins-Aktivierungslink `GET /verein-aktivieren/{token}`.
     * iOS fängt den Universal Link ab und aktiviert in der App; im Browser bewerben
     * wir die App (kein Token-Detail-Leak). Aktivierung erfordert die eingeloggte App.
     */
    public function activate(Request $req): void
    {
        $token = (string)($req->routeParams['token'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/i', $token) !== 1) {
            Response::redirect('/');
        }
        $this->view->render('crew/activate', [
            '_title'        => 'Vereins-Account aktivieren · CYBERRIDE',
            'open_url'      => 'https://cyberride.world/verein-aktivieren/' . rawurlencode($token),
            'app_store_url' => (string)$this->config->get('APP_STORE_URL', ''),
        ]);
    }
}
