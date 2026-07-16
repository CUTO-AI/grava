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
        $this->service->setLanguage($this->negotiateLang($req));
        $parsed = MapLod::parseBbox((string)($req->query['bbox'] ?? ''));
        if ($parsed === null) {
            Response::error('bad_request', 'bbox erforderlich (minLon,minLat,maxLon,maxLat).', 400);
        }
        [$minLon, $minLat, $maxLon, $maxLat] = $parsed;
        $level = $this->parseLevel($req->query['level'] ?? null);
        $geometry = in_array((string)($req->query['geometry'] ?? '0'), ['1', 'true', 'yes'], true);
        $ownedOnly = in_array((string)($req->query['owned'] ?? '0'), ['1', 'true', 'yes'], true);

        Response::json($this->service->regionsInBbox(
            $minLon, $minLat, $maxLon, $maxLat, $level, $geometry, $this->viewerClaimant($req), $ownedOnly,
        ));
    }

    /** GET /game/regions/{id} */
    public function detail(Request $req): void
    {
        $this->service->setLanguage($this->negotiateLang($req));
        $id = (int)($req->routeParams['id'] ?? 0);
        $detail = $this->service->regionDetail($id, $this->viewerClaimant($req));
        if ($detail === null) {
            Response::error('not_found', 'Gebiet nicht gefunden.', 404);
        }
        Response::json($detail);
    }

    /** GET /game/regions/{id}/activity?days=7|30 — Nordstern-Aktivität (WAR + Solo/Crew-Rangliste). */
    public function activity(Request $req): void
    {
        $this->service->setLanguage($this->negotiateLang($req));
        $id = (int)($req->routeParams['id'] ?? 0);
        $out = $this->service->regionActivity($id, $this->parseDays($req->query['days'] ?? null));
        if ($out === null) {
            Response::error('not_found', 'Gebiet nicht gefunden.', 404);
        }
        Response::json($out);
    }

    /** GET /game/regions/activity-overview?days=&level=&bbox= — WAR je Gebiet (Karte/Admin). */
    public function activityOverview(Request $req): void
    {
        $this->service->setLanguage($this->negotiateLang($req));
        $bbox = MapLod::parseBbox((string)($req->query['bbox'] ?? ''));
        Response::json($this->service->warOverview(
            $this->parseDays($req->query['days'] ?? null),
            $this->parseLevel($req->query['level'] ?? null),
            $bbox,
        ));
    }

    /** GET /game/me/regions?level= (Bearer). */
    public function mine(Request $req): void
    {
        $this->service->setLanguage($this->negotiateLang($req));
        $u = $req->user;
        $uid = $u !== null ? (int)($u->internal_id ?? 0) : 0;
        if ($uid <= 0) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }
        $claimant = $this->repo->effectiveClaimantId($uid);
        Response::json($this->service->myRegions($claimant, $this->parseLevel($req->query['level'] ?? null)));
    }

    /**
     * Anzeigesprache für Gebietsnamen aus dem Accept-Language-Header (App + Browser
     * senden ihn). Nur die höchstpriorisierte Sprache zählt; 'de*' → Deutsch, alles
     * andere → international/englisch. Beispiel: "de-DE,de;q=0.9,en;q=0.8" → 'de'.
     */
    private function negotiateLang(Request $req): string
    {
        $header = strtolower(trim($req->header('Accept-Language')));
        if ($header === '') {
            return 'en';
        }
        // Erste Sprach-Range vor dem ersten Komma / Semikolon.
        $first = trim(explode(',', $header)[0]);
        $first = trim(explode(';', $first)[0]);
        return str_starts_with($first, 'de') ? 'de' : 'en';
    }

    /** Zeitfenster in Tagen — nur 7 oder 30 zulässig (Default 7). */
    private function parseDays(mixed $raw): int
    {
        $d = is_scalar($raw) ? (int)$raw : 0;
        return in_array($d, [7, 30], true) ? $d : 7;
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
