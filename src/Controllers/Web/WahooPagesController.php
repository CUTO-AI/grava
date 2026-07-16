<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\WebSession;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Integrations\Wahoo\WahooException;
use App\Integrations\Wahoo\WahooService;

/**
 * Web-Rückkanal der Wahoo-Integration (Phase B). Nur der OAuth-Callback — der
 * eigentliche Connect startet mobil über die API (connect-url). Analog dem
 * Callback-Teil von {@see StravaPagesController}.
 *
 *   GET /auth/wahoo/callback   OAuth-Rückkanal (Wahoo-Redirect-Ziel)
 */
final class WahooPagesController
{
    public function __construct(
        private readonly WebSession $webSession,
        private readonly WahooService $wahoo,
    ) {}

    public function callback(Request $req): void
    {
        $state = (string)($req->query['state'] ?? '');
        $code  = (string)($req->query['code'] ?? '');
        $scope = (string)($req->query['scope'] ?? '');
        $err   = (string)($req->query['error'] ?? '');

        // Mobile-Flow (ASWebAuthenticationSession) hat keine Web-Session — daher
        // KEIN requireUser(); die Bindung erzwingt handleCallback() über den State.
        $ctx = $this->webSession->resolve();
        $expectedUserId = $ctx !== null ? (int)$ctx['user_id'] : null;

        if ($err !== '') {
            $this->finish(null, 'error', 'Wahoo-Verbindung abgebrochen: ' . $err);
        }

        try {
            $res = $this->wahoo->handleCallback($state, $code, $expectedUserId, $scope === '' ? null : $scope);
        } catch (WahooException $e) {
            $this->finish(null, 'error', 'Fehler: ' . $e->getMessage());
        }

        $this->finish($res['return_to'], 'connected', 'Wahoo verbunden. Du kannst jetzt Fahrten importieren.');
    }

    /**
     * Schließt den Callback ab: Deep-Link zurück in die App (Mobile mit return_to)
     * oder Flash + Settings-Seite (Web).
     *
     * @param string $status connected|error
     */
    private function finish(?string $returnTo, string $status, string $message): never
    {
        if ($returnTo !== null && $returnTo !== '') {
            $sep = str_contains($returnTo, '?') ? '&' : '?';
            Response::redirect($returnTo . $sep . 'wahoo=' . $status);
        }
        Csrf::ensureStarted();
        $_SESSION['flash'] = $message;
        Response::redirect('/settings/integrations');
    }
}
