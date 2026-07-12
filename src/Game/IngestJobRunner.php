<?php
declare(strict_types=1);

namespace App\Game;

use App\Routes\GeometryParser;
use App\Routes\RadarTrafficData;
use App\Routes\RadarTrafficParser;
use App\Routes\RouteService;
use App\Support\Clock;

/**
 * Führt einen Ingest für eine vorhandene Route vollständig aus — das schwere
 * Map-Matching (Valhalla, 50-km-Chunks) inkl. der Gebiets-Nachpflege.
 *
 * Geteilt zwischen dem asynchronen Cron-Worker (game:ingest-run) und potenziell
 * Admin-Tools. Der HTTP-Endpoint macht nur noch das schnelle Enqueue; die Arbeit
 * hier läuft entkoppelt vom Client-Timeout (siehe Migration 0054).
 *
 * Wirft {@see \App\Game\MatchUnavailableException} (Routing-Engine down) und
 * {@see \App\Routes\GeometryParseException} (kaputte Geometrie) nach oben — der
 * Worker mappt sie auf einen failed-Job mit sprechendem error_code.
 */
final class IngestJobRunner
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly RouteService $routes,
        private readonly GeometryParser $parser,
        private readonly GameIngestionService $ingest,
        private readonly ?RegionImportService $regionImport = null,
        private readonly ?RegionOwnershipService $regionOwnership = null,
        private readonly ?GameEventRecorder $regionEvents = null,
    ) {}

    /**
     * @return array<string,mixed> Ingest-Summary (wie der frühere synchrone Endpoint)
     */
    public function run(int $routeId, int $userId): array
    {
        $route = $this->repo->resolveRouteForIngest((string)$routeId);
        if ($route === null) {
            throw new IngestRouteGoneException('Route nicht mehr vorhanden.');
        }
        $loaded = $this->routes->loadPayloadByPublicId($route['public_id']);
        $parsed = $this->parser->parse($loaded['payload']);

        // Strava-/GPX-Importe gelten vorerst als „echt" (keine Motion-/Surface-/
        // Radar-Daten, sollen aber Besitz beanspruchen können) → Motion-Auth-Filter
        // umgehen; Day-Cap/Privatzonen/Start-Puffer/Wertlogik bleiben.
        $trusted = GameIngestionService::isTrustedSource($route['source']);
        $radar = $parsed->sourceFormat === 'gpx'
            ? RadarTrafficParser::parse($loaded['payload'])
            : RadarTrafficData::empty();

        $summary = $this->ingest->ingest(
            (int)$route['route_id'],
            $userId,
            $parsed,
            $parsed->startedAt !== null,
            null,
            $radar,
            $trusted,
        );

        // Gebiets-Eroberung live halten: neu erschlossene Kanten ihrem Gebiet
        // zuordnen und den Besitz-Cache neu rechnen. Best-effort — Fehler dürfen
        // den erfolgreichen Ingest nie kippen.
        if (($summary['matched'] ?? 0) > 0) {
            try {
                $this->regionImport?->backfillEdges(true, 500);
                $res = $this->regionOwnership?->recomputeAll();
                if ($res !== null && $this->regionEvents !== null && ($res['changes'] ?? []) !== []) {
                    $this->regionEvents->recordRegionChanges(
                        $res['changes'], $userId, Clock::nowUtc()->format('Y-m-d'),
                    );
                }
            } catch (\Throwable $e) {
                error_log('region refresh nach async-ingest fehlgeschlagen: ' . $e->getMessage());
            }
        }

        return $summary;
    }
}
