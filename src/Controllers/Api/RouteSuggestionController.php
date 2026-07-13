<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\GameRepository;
use App\Game\RouteSuggestionService;
use App\Http\Request;
use App\Http\Response;

/**
 * HTTP-Adapter für den Eroberungs-Routenvorschlag (RouteSuggestion_Concept.md):
 *   POST /game/route-suggestion   (Bearer + verifiziert)
 *
 * BETA (Phase B): noch OHNE Pro-Gate und OHNE Rate-Limit — beides folgt in
 * Phase D. Der effektive Claimant wird wie bei /game/edges bestimmt.
 */
final class RouteSuggestionController
{
    public function __construct(
        private readonly RouteSuggestionService $service,
        private readonly GameRepository $repo,
    ) {}

    /** POST /game/route-suggestion */
    public function suggest(Request $req): void
    {
        $u = $req->user;
        $uid = $u !== null ? (int)($u->internal_id ?? 0) : 0;
        if ($uid <= 0) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }

        $lat = $this->floatOrNull($req->input('start_lat'));
        $lon = $this->floatOrNull($req->input('start_lon'));
        if ($lat === null || $lon === null || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            Response::error('validation_error', 'start_lat/start_lon erforderlich (gültige Koordinaten).', 422);
        }

        $maxKm = $this->floatOrNull($req->input('max_km')) ?? 30.0;
        $loop = filter_var($req->input('loop') ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

        $claimant = $this->repo->effectiveClaimantId($uid);
        $out = $this->service->suggest($claimant, $uid, $lat, $lon, $maxKm, $loop);
        if ($out === null) {
            Response::error('no_candidates', 'Keine eroberbaren Kanten in Reichweite. Radius oder Budget erhöhen.', 409);
        }
        Response::json($out);
    }

    private function floatOrNull(mixed $v): ?float
    {
        return is_scalar($v) && is_numeric((string)$v) ? (float)$v : null;
    }
}
