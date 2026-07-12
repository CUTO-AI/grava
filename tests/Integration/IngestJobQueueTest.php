<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Game\IngestJobRepository;
use PDO;
use Tests\IntegrationTestCase;

/**
 * Sichert die Queue-Mechanik des asynchronen Ingest ab (Migration 0054):
 * Enqueue ist idempotent pro Route, claimNext reserviert atomar genau einmal,
 * und markDone/markFailed schreiben den Endzustand. Der eigentliche Ingest-Run
 * (Valhalla-Map-Matching) ist hier bewusst NICHT Teil des Tests — er braucht die
 * Routing-Engine und wird separat/manuell verifiziert.
 */
final class IngestJobQueueTest extends IntegrationTestCase
{
    private function internalRouteId(string $publicId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM routes WHERE public_id = ?');
        $stmt->execute([$publicId]);
        return (int)$stmt->fetchColumn();
    }

    public function testEnqueueIsIdempotentPerRoute(): void
    {
        $userId  = $this->createUser();
        $routeId = $this->internalRouteId($this->createRoute($userId));
        $repo    = new IngestJobRepository($this->pdo);

        $first  = $repo->enqueue($routeId, $userId);
        $second = $repo->enqueue($routeId, $userId);

        $this->assertSame($first, $second, 'Zweites Enqueue liefert denselben Job (UNIQUE route_id).');

        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM game_ingest_jobs')->fetchColumn();
        $this->assertSame(1, $count, 'Kein Duplikat-Job.');

        $job = $repo->find($first);
        $this->assertNotNull($job);
        $this->assertSame('queued', $job['status']);
        $this->assertSame(0, (int)$job['attempts']);
    }

    public function testClaimNextReservesExactlyOnce(): void
    {
        $userId  = $this->createUser();
        $routeId = $this->internalRouteId($this->createRoute($userId));
        $repo    = new IngestJobRepository($this->pdo);
        $jobId   = $repo->enqueue($routeId, $userId);

        $claimed = $repo->claimNext();
        $this->assertNotNull($claimed);
        $this->assertSame($jobId, (int)$claimed['id']);
        $this->assertSame($routeId, (int)$claimed['route_id']);
        $this->assertSame($userId, (int)$claimed['user_id']);

        $job = $repo->find($jobId);
        $this->assertSame('running', $job['status']);
        $this->assertSame(1, (int)$job['attempts']);

        $this->assertNull($repo->claimNext(), 'Kein zweiter Job in der Queue.');
    }

    public function testMarkDoneStoresSummaryAndReEnqueueResets(): void
    {
        $userId  = $this->createUser();
        $routeId = $this->internalRouteId($this->createRoute($userId));
        $repo    = new IngestJobRepository($this->pdo);
        $jobId   = $repo->enqueue($routeId, $userId);
        $repo->claimNext();

        $repo->markDone($jobId, '{"matched":5,"passes_new":3}');
        $job = $repo->find($jobId);
        $this->assertSame('done', $job['status']);
        $this->assertSame('{"matched":5,"passes_new":3}', $job['summary_json']);

        // Erneutes „aufnehmen" setzt denselben Job zurück (idempotent).
        $again = $repo->enqueue($routeId, $userId);
        $this->assertSame($jobId, $again);
        $job = $repo->find($jobId);
        $this->assertSame('queued', $job['status']);
        $this->assertNull($job['summary_json']);
        $this->assertSame(0, (int)$job['attempts']);
    }

    public function testMarkFailedRecordsError(): void
    {
        $userId  = $this->createUser();
        $routeId = $this->internalRouteId($this->createRoute($userId));
        $repo    = new IngestJobRepository($this->pdo);
        $jobId   = $repo->enqueue($routeId, $userId);
        $repo->claimNext();

        $repo->markFailed($jobId, 'routing_unavailable', 'Valhalla nicht erreichbar.');
        $job = $repo->find($jobId);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('routing_unavailable', $job['error_code']);
        $this->assertSame('Valhalla nicht erreichbar.', $job['error_message']);
    }
}
