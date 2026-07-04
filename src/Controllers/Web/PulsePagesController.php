<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Http\Middleware\Csrf;
use App\Http\Request;

/**
 * Öffentliche „Heute im Spiel" (Pulse)-Seite. Zeigt Besuchern die laufenden
 * Kennzahlen aus dem Spiel: Tagesbericht der Eroberungen, Team-Rangliste,
 * Fraktionsstand, neue Rekorde, Pioniere, Tages-Zahlen, umkämpfteste Region
 * und einen Live-Ereignis-Feed. Daten holt die Seite clientseitig same-origin
 * von GET /api/v1/pulse (pulse.js), mit Auto-Refresh.
 */
final class PulsePagesController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function show(Request $req): void
    {
        $user = null;
        $ctx = $this->webSession->resolve();
        if ($ctx !== null) {
            $user = $this->auth->loadUserPublic($ctx['user_id']);
            Csrf::ensureStarted();
        }

        $this->view->render('pulse', [
            '_title'       => 'Heute im Spiel · CYBERRIDE',
            '_authedUser'  => $user,
            '_pageStyles'  => ['/assets/css/pulse.css'],
            '_pageScripts' => ['/assets/js/pulse.js'],
            '_layoutWide'  => true,
        ]);
    }
}
