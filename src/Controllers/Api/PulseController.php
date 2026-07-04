<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\PulseService;
use App\Http\Request;
use App\Http\Response;

/**
 * GET /pulse — öffentliches „Heute im Spiel"-Aggregat für die /pulse-Webseite.
 * Rein aggregierte, anonyme Werte; kurz cachebar (Auto-Refresh clientseitig).
 */
final class PulseController
{
    public function __construct(private readonly PulseService $pulse) {}

    public function index(Request $req): void
    {
        Response::json(
            $this->pulse->snapshot(),
            200,
            ['Cache-Control' => 'public, max-age=30'],
        );
    }
}
