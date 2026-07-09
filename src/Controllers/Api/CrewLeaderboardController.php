<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\CrewLeaderboardService;
use App\Http\Request;
use App\Http\Response;

/**
 * HTTP-Adapter für GET /game/crews/leaderboard (öffentlich, OptionalBearer):
 * globale Crew-Rangliste nach gehaltener Revierlänge (all-time).
 * Logik liegt in {@see CrewLeaderboardService}.
 */
final class CrewLeaderboardController
{
    public function __construct(private readonly CrewLeaderboardService $service) {}

    public function index(Request $req): void
    {
        Response::json($this->service->leaderboard());
    }
}
