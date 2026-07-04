<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Auth\RateLimiter;
use App\Engagement\EngagementException;
use App\Engagement\ReportService;
use App\Http\Request;
use App\Http\Response;

/**
 * Melde-Endpoints für anstößige Inhalte (App-Store-Richtlinie 1.2, UGC):
 *
 *   POST /api/v1/routes/{id}/report                    Bearer; 201
 *   POST /api/v1/routes/{id}/comments/{cid}/report     Bearer; 201
 *   POST /api/v1/users/by-handle/{handle}/report       Bearer; 201
 *
 * Body (application/json): { "reason": spam|abuse|harassment|explicit|other,
 *                            "description": "optionaler Text (max. 500)" }
 * Nicht sichtbare/blockierte Ziele → 404 (wie übrige Engagement-Endpoints).
 */
final class ReportController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly RateLimiter $rate,
    ) {}

    public function reportRoute(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        $this->guardRate($viewer);
        [$reason, $desc] = $this->params($req);
        try {
            $this->reports->reportRoute((string)($req->routeParams['id'] ?? ''), $viewer, $reason, $desc);
        } catch (EngagementException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json(['ok' => true], 201);
    }

    public function reportComment(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        $this->guardRate($viewer);
        [$reason, $desc] = $this->params($req);
        try {
            $this->reports->reportComment(
                (string)($req->routeParams['id'] ?? ''),
                (int)($req->routeParams['cid'] ?? 0),
                $viewer,
                $reason,
                $desc,
            );
        } catch (EngagementException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json(['ok' => true], 201);
    }

    public function reportUser(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        $this->guardRate($viewer);
        [$reason, $desc] = $this->params($req);
        try {
            $this->reports->reportUser((string)($req->routeParams['handle'] ?? ''), $viewer, $reason, $desc);
        } catch (EngagementException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json(['ok' => true], 201);
    }

    /** @return array{0:string,1:?string} reason + optionale Beschreibung */
    private function params(Request $req): array
    {
        $reason = (string)($req->json['reason'] ?? $req->post['reason'] ?? 'other');
        $descRaw = $req->json['description'] ?? $req->post['description'] ?? null;
        $desc = is_string($descRaw) && trim($descRaw) !== '' ? $descRaw : null;
        return [$reason, $desc];
    }

    private function guardRate(int $viewer): void
    {
        // Spam-Schutz analog Kommentar-Erstellung: max. 20 Meldungen / Fenster.
        if ($this->rate->hit('content_report', 'u:' . $viewer, 20)) {
            header('Retry-After: ' . $this->rate->retryAfter());
            Response::error('rate_limited', 'Zu viele Meldungen. Bitte später erneut.', 429);
        }
    }
}
