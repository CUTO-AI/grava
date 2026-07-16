<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Integrations\Wahoo\WahooService;

/**
 * Wahoo-Integration (API, Bearer-required) — Phase B (OAuth). Import folgt
 * in Phase D. Analog {@see IntegrationsController} (Strava), aber import-only.
 *
 *   GET    /api/v1/integrations/wahoo             Status der Verbindung
 *   GET    /api/v1/integrations/wahoo/connect-url  Mobile-Connect (Authorize-URL)
 *   DELETE /api/v1/integrations/wahoo             trennt die Verbindung
 *
 * Der OAuth-Callback läuft über die Web-Route /auth/wahoo/callback
 * (Wahoo-Redirect-Ziel), für Mobile session-los mit Deep-Link-Rückkehr.
 */
final class WahooController
{
    public function __construct(private readonly WahooService $wahoo) {}

    public function status(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        Response::json($this->wahoo->status($userId));
    }

    public function connectUrl(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        if (!$this->wahoo->isConfigured()) {
            Response::error('not_configured', 'Wahoo ist serverseitig nicht konfiguriert.', 503);
        }
        $returnTo = self::sanitizeReturnTo((string)($req->query['return_to'] ?? ''));
        $url = $this->wahoo->authorizeUrl($userId, 'mobile', $returnTo);
        Response::json(['authorize_url' => $url, 'return_to' => $returnTo]);
    }

    public function disconnect(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        $this->wahoo->disconnect($userId);
        Response::noContent();
    }

    /**
     * Nur eigene App-Schemes / die eigene Domain als Rückkehrziel erlauben
     * (Open-Redirect-Schutz). Default: Custom-Scheme-Deep-Link.
     */
    private static function sanitizeReturnTo(string $v): string
    {
        $v = trim($v);
        if ($v !== '' && (str_starts_with($v, 'grava://') || str_starts_with($v, 'https://cyberride.world/'))) {
            return mb_substr($v, 0, 255);
        }
        return 'grava://wahoo-connected';
    }
}
