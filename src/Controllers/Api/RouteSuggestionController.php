<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Auth\RateLimiter;
use App\Game\EntitlementService;
use App\Game\GameConfig;
use App\Game\GameRepository;
use App\Game\RouteSuggestionService;
use App\Http\Request;
use App\Http\Response;

/**
 * HTTP-Adapter für den Eroberungs-Routenvorschlag (RouteSuggestion_Concept.md):
 *   POST /game/route-suggestion   (Bearer + verifiziert)
 *   GET  /game/entitlements       (Bearer)
 *
 * Phase D vorbereitet, aber OFFEN: Solange `pro_gating_enabled` = 0 ist, darf
 * jede:r den Vorschlag nutzen ({@see EntitlementService::allowsPro()}). Das
 * Tages-Limit greift nur, wenn `route_suggestion_daily_limit` > 0 gesetzt wird.
 */
final class RouteSuggestionController
{
    public function __construct(
        private readonly RouteSuggestionService $service,
        private readonly GameRepository $repo,
        private readonly EntitlementService $entitlements,
        private readonly RateLimiter $rateLimiter,
        private readonly GameConfig $config,
    ) {}

    /** POST /game/route-suggestion */
    public function suggest(Request $req): void
    {
        $uid = $this->userId($req);

        // Pro-Gate — no-op, solange das Flag aus ist (Beta: alles offen).
        if (!$this->entitlements->allowsPro($uid)) {
            Response::error('payment_required', 'Diese Funktion ist Teil von CYBERRIDE Pro.', 402);
        }

        // Optionales Tages-Limit (0 = unbegrenzt).
        $dailyLimit = $this->config->int('route_suggestion_daily_limit');
        if ($dailyLimit > 0 && $this->rateLimiter->hitDaily('route_suggestion', (string)$uid, $dailyLimit)) {
            Response::error('rate_limited', 'Tages-Limit für Routenvorschläge erreicht. Morgen wieder.', 429);
        }

        $lat = $this->floatOrNull($req->input('start_lat'));
        $lon = $this->floatOrNull($req->input('start_lon'));
        if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            Response::error('validation_error', 'start_lat/start_lon erforderlich (gültige Koordinaten).', 422);
        }

        $maxKm = $this->floatOrNull($req->input('max_km')) ?? 30.0;
        $loop = filter_var($req->input('loop') ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        $claimant = $this->repo->effectiveClaimantId($uid);
        $res = $this->service->suggest($claimant, $uid, $lat, $lon, $maxKm, $loop);

        switch ($res['reason'] ?? 'no_candidates') {
            case 'ok':
                Response::json($res);
                return;
            case 'routing_failed':
                // Kanten waren da, aber keine fahrbare Runde möglich — eigener Code,
                // damit der Client (und wir) es von „keine Kanten" unterscheiden kann.
                Response::error(
                    'routing_failed',
                    'Kanten in der Nähe gefunden, aber es ließ sich keine fahrbare Runde bilden. Versuch eine andere Distanz oder einen anderen Start.',
                    502
                );
                return;
            default:
                Response::error(
                    'no_candidates',
                    'Keine eroberbaren Kanten in Reichweite. Radius/Budget erhöhen oder anderen Start wählen.',
                    409
                );
                return;
        }
    }

    /** GET /game/reachable-count?lat=&lon=&km= — Anzahl eroberbarer Kanten in der Nähe. */
    public function reachableCount(Request $req): void
    {
        $uid = $this->userId($req);
        $lat = $this->floatOrNull($req->query['lat'] ?? null);
        $lon = $this->floatOrNull($req->query['lon'] ?? null);
        if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            Response::error('validation_error', 'lat/lon erforderlich (gültige Koordinaten).', 422);
        }
        $km = $this->floatOrNull($req->query['km'] ?? null) ?? 10.0;
        $claimant = $this->repo->effectiveClaimantId($uid);
        Response::json(['reachable' => $this->service->reachableCount($claimant, $uid, $lat, $lon, $km)]);
    }

    /** GET /game/entitlements — was der Client zeigen/gaten soll. */
    public function entitlements(Request $req): void
    {
        $uid = $this->userId($req);
        Response::json([
            'pro'            => $this->entitlements->isPro($uid),
            'gating_enabled' => $this->entitlements->gatingEnabled(),
        ]);
    }

    private function userId(Request $req): int
    {
        $u = $req->user;
        $uid = $u !== null ? (int)($u->internal_id ?? 0) : 0;
        if ($uid <= 0) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }
        return $uid;
    }

    private function floatOrNull(mixed $v): ?float
    {
        return is_scalar($v) && is_numeric((string)$v) ? (float)$v : null;
    }
}
