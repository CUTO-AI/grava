<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\GameRepository;
use App\Game\RegionService;
use App\Http\Request;
use App\Http\Response;
use App\Support\MapLod;

/**
 * HTTP-Adapter für die Gebiets-Eroberung (CityConquest_Backend_Spec.md, Phase B):
 *   GET /game/regions?bbox=&level=&geometry=  (OptionalBearer)
 *   GET /game/regions/{id}                    (OptionalBearer)
 *   GET /game/me/regions?level=               (Bearer)
 * Logik liegt in {@see RegionService}; der effektive Claimant (Crew, wenn
 * Mitglied) wird wie bei /game/edges bestimmt.
 */
final class RegionController
{
    public function __construct(
        private readonly RegionService $service,
        private readonly GameRepository $repo,
    ) {}

    /** GET /game/regions?bbox=&level=&geometry= */
    public function index(Request $req): void
    {
        $parsed = MapLod::parseBbox((string)($req->query['bbox'] ?? ''));
        if ($parsed === null) {
            Response::error('bad_request', 'bbox erforderlich (minLon,minLat,maxLon,maxLat).', 400);
        }
        [$minLon, $minLat, $maxLon, $maxLat] = $parsed;
        $level = $this->parseLevel($req->query['level'] ?? null);
        $geometry = in_array((string)($req->query['geometry'] ?? '0'), ['1', 'true', 'yes'], true);

        Response::json($this->service->regionsInBbox(
            $minLon, $minLat, $maxLon, $maxLat, $level, $geometry, $this->viewerClaimant($req),
        ));
    }

    /** GET /game/regions/{id} */
    public function detail(Request $req): void
    {
        $id = (int)($req->routeParams['id'] ?? 0);
        $detail = $this->service->regionDetail($id, $this->viewerClaimant($req));
        if ($detail === null) {
            Response::error('not_found', 'Gebiet nicht gefunden.', 404);
        }
        Response::json($detail);
    }

    /** GET /game/me/regions?level= (Bearer). */
    public function mine(Request $req): void
    {
        $u = $req->user;
        $uid = $u !== null ? (int)($u->internal_id ?? 0) : 0;
        if ($uid <= 0) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }
        $claimant = $this->repo->effectiveClaimantId($uid);
        Response::json($this->service->myRegions($claimant, $this->parseLevel($req->query['level'] ?? null)));
    }

    private function parseLevel(mixed $raw): ?int
    {
        if (!is_scalar($raw) || (string)$raw === '' || !is_numeric((string)$raw)) {
            return null;
        }
        $lvl = (int)$raw;
        return in_array($lvl, [2, 4, 6, 8], true) ? $lvl : null;
    }

    private function viewerClaimant(Request $req): ?int
    {
        $u = $req->user;
        if ($u === null) {
            return null;
        }
        $uid = (int)($u->internal_id ?? 0);
        return $uid > 0 ? $this->repo->effectiveClaimantId($uid) : null;
    }
}
