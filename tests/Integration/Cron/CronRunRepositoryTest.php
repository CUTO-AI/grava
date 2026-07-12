<?php
declare(strict_types=1);

namespace Tests\Integration\Cron;

use App\Cli\CronRunRepository;
use App\Support\Clock;
use PDO;
use Tests\IntegrationTestCase;

/**
 * Queue-/Historie-Mechanik des Cron-Monitorings (Migration 0055): begin/finish,
 * Aggregate, p95, Stuck-Sweep, Retention und die Idle-Zusammenfassung.
 */
final class CronRunRepositoryTest extends IntegrationTestCase
{
    private function seed(
        string $cmd,
        string $status,
        ?int $dur,
        string $startedAt,
        int $didWork = 1,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO cron_runs (command, status, duration_ms, started_at, finished_at, did_work)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$cmd, $status, $dur, $startedAt, $startedAt, $didWork]);
        return (int)$this->pdo->lastInsertId();
    }

    private function at(string $modify): string
    {
        return Clock::nowUtc()->modify($modify)->format('Y-m-d H:i:s');
    }

    public function testBeginThenFinish(): void
    {
        $repo = new CronRunRepository($this->pdo);
        $id = $repo->begin('game:ingest-run', 'cron', 'host1');
        $running = $repo->history('game:ingest-run', 10, 0)[0];
        $this->assertSame('running', $running['status']);

        $repo->finish($id, 'ok', 0, 1234, 'output tail', null, true);
        $done = $repo->history('game:ingest-run', 10, 0)[0];
        $this->assertSame('ok', $done['status']);
        $this->assertSame(1234, (int)$done['duration_ms']);
        $this->assertSame('output tail', $done['output_tail']);
        $this->assertNotNull($done['finished_at']);
    }

    public function testLatestAndLastSuccessPerCommand(): void
    {
        $repo = new CronRunRepository($this->pdo);
        $this->seed('regions:backfill', 'ok', 100, $this->at('-30 minutes'));
        $this->seed('regions:backfill', 'ok', 110, $this->at('-20 minutes'));
        $this->seed('regions:backfill', 'failed', 90, $this->at('-5 minutes'));

        $latest = $repo->latestPerCommand();
        $this->assertSame('failed', $latest['regions:backfill']['status']);

        $lastOk = $repo->lastSuccessPerCommand();
        $this->assertSame(110, (int)$lastOk['regions:backfill']['duration_ms']);
    }

    public function testAggregates24hExcludesOldAndRunning(): void
    {
        $repo = new CronRunRepository($this->pdo);
        $this->seed('game:notify-dispatch', 'ok', 100, $this->at('-1 hour'));
        $this->seed('game:notify-dispatch', 'ok', 300, $this->at('-2 hours'));
        $this->seed('game:notify-dispatch', 'failed', 50, $this->at('-3 hours'));
        $this->seed('game:notify-dispatch', 'ok', 999, $this->at('-2 days'));    // außerhalb 24h
        $this->seed('game:notify-dispatch', 'running', null, $this->at('-1 minute')); // laufend

        $agg = $repo->aggregates24h()['game:notify-dispatch'];
        $this->assertSame(3, $agg['runs']);        // 2 ok + 1 failed, ohne alt/running
        $this->assertSame(1, $agg['failures']);
        $this->assertSame(300, $agg['max_ms']);
    }

    public function testP95Recent(): void
    {
        $repo = new CronRunRepository($this->pdo);
        for ($i = 1; $i <= 100; $i++) {
            $this->seed('cron:cleanup', 'ok', $i, $this->at("-{$i} minutes"));
        }
        // sortiert 1..100, Index ceil(0.95*100)-1 = 94 → Wert 95.
        $this->assertSame(95, $repo->p95Recent('cron:cleanup'));
    }

    public function testSweepStuckMarksOldRunningOnly(): void
    {
        $repo = new CronRunRepository($this->pdo);
        $old = $this->seed('game:ingest-run', 'running', null, $this->at('-20 minutes'));
        $fresh = $this->seed('game:ingest-run', 'running', null, $this->at('-10 seconds'));

        $marked = $repo->sweepStuck(['game:ingest-run' => 300], 900);
        $this->assertSame(1, $marked);

        $rows = [];
        foreach ($repo->history('game:ingest-run', 10, 0) as $r) { $rows[(int)$r['id']] = $r; }
        $this->assertSame('failed', $rows[$old]['status']);
        $this->assertStringContainsString('stuck', (string)$rows[$old]['error_message']);
        $this->assertSame('running', $rows[$fresh]['status']);
    }

    public function testPruneOlderThan(): void
    {
        $repo = new CronRunRepository($this->pdo);
        $this->seed('cron:cleanup', 'ok', 10, $this->at('-20 days'));
        $this->seed('cron:cleanup', 'ok', 10, $this->at('-1 day'));

        $this->assertSame(1, $repo->pruneOlderThan(14));
        $this->assertSame(1, $repo->historyCount('cron:cleanup'));
    }

    public function testRecordIdleCollapsesPerDay(): void
    {
        $repo = new CronRunRepository($this->pdo);
        $repo->recordIdle('game:ingest-run', 'host1', 5);
        $repo->recordIdle('game:ingest-run', 'host1', 7);
        // Zwei Idle-Ticks → nur EINE Heartbeat-Zeile.
        $this->assertSame(1, $repo->historyCount('game:ingest-run'));
        $row = $repo->history('game:ingest-run', 10, 0)[0];
        $this->assertSame(0, (int)$row['did_work']);
        $this->assertSame(7, (int)$row['duration_ms']);   // aktualisiert

        // Ein echter Arbeitslauf bekommt eine eigene Zeile.
        $id = $repo->begin('game:ingest-run', 'cron', 'host1');
        $repo->finish($id, 'ok', 0, 42, 'did work', null, true);
        $this->assertSame(2, $repo->historyCount('game:ingest-run'));

        // Weiterer Idle-Tick → aktualisiert weiterhin nur die Heartbeat-Zeile.
        $repo->recordIdle('game:ingest-run', 'host1', 9);
        $this->assertSame(2, $repo->historyCount('game:ingest-run'));
    }
}
