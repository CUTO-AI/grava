<?php
declare(strict_types=1);

namespace App\Game\Admin;

use App\Cli\CronRegistry;
use App\Cli\CronRunRepository;
use App\Presence\PresenceService;
use App\Support\Clock;
use PDO;

/**
 * Aggregiert die KPIs der Backoffice-Startseite (`/admin`, GameAdmin_Concept.md
 * Modul A). Bündelt günstige COUNT-Queries (nach created_at indexiert) und
 * vorhandene Dienste (Presence, Cron-Monitoring). Keine Schreibvorgänge.
 */
final class DashboardAdminService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PresenceService $presence,
        private readonly CronRunRepository $cronRuns,
        private readonly int $overdueFactor = 2,
    ) {}

    /** @return array<string,mixed> */
    public function metrics(): array
    {
        $now = Clock::nowUtc();
        $today = $now->format('Y-m-d') . ' 00:00:00';
        $d7 = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $d30 = $now->modify('-30 days')->format('Y-m-d H:i:s');
        $h24 = $now->modify('-24 hours')->format('Y-m-d H:i:s');

        return [
            'active_now'      => $this->presence->activeCount(),

            'signups_today'   => $this->count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$today]),
            'signups_7d'      => $this->count('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$d7]),
            'users_total'     => $this->count('SELECT COUNT(*) FROM users', []),
            'banned'          => $this->count('SELECT COUNT(*) FROM game_user_flag WHERE banned = 1', []),

            'rides_today'     => $this->count('SELECT COUNT(*) FROM routes WHERE deleted_at IS NULL AND created_at >= ?', [$today]),
            'rides_7d'        => $this->count('SELECT COUNT(*) FROM routes WHERE deleted_at IS NULL AND created_at >= ?', [$d7]),

            // „Aktive Fahrer" = distinct Uploader je Fenster (Proxy für DAU/WAU/MAU).
            'dau'             => $this->count('SELECT COUNT(DISTINCT user_id) FROM routes WHERE deleted_at IS NULL AND created_at >= ?', [$today]),
            'wau'             => $this->count('SELECT COUNT(DISTINCT user_id) FROM routes WHERE deleted_at IS NULL AND created_at >= ?', [$d7]),
            'mau'             => $this->count('SELECT COUNT(DISTINCT user_id) FROM routes WHERE deleted_at IS NULL AND created_at >= ?', [$d30]),

            'ingest_queue'    => $this->count("SELECT COUNT(*) FROM game_ingest_jobs WHERE status IN ('queued','running')", []),
            'ingest_failed_24h' => $this->count("SELECT COUNT(*) FROM game_ingest_jobs WHERE status = 'failed' AND finished_at >= ?", [$h24]),

            'reports_open'    => $this->count("SELECT COUNT(*) FROM content_report WHERE status = 'open'", []),

            'cron'            => $this->cronHealth($now),
        ];
    }

    /** @return array{failed:int,overdue:int} */
    private function cronHealth(\DateTimeImmutable $now): array
    {
        $latest = $this->cronRuns->latestPerCommand();
        $failed = 0;
        $overdue = 0;
        foreach (CronRegistry::commands() as $cmd) {
            $meta = CronRegistry::meta($cmd);
            $row = $latest[$cmd] ?? null;
            if ($row !== null && ($row['status'] ?? '') === 'failed') {
                $failed++;
            }
            if ($row === null) {
                $overdue++;
                continue;
            }
            try {
                $age = $now->getTimestamp() - (new \DateTimeImmutable((string)$row['started_at'], new \DateTimeZone('UTC')))->getTimestamp();
                if ($age > ($meta['interval_s'] ?? 3600) * $this->overdueFactor) {
                    $overdue++;
                }
            } catch (\Throwable) {
                // ignoriert — Zeitparsing sollte nie werfen
            }
        }
        return ['failed' => $failed, 'overdue' => $overdue];
    }

    /** @param list<mixed> $params */
    private function count(string $sql, array $params): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), '1146')) {
                throw $e;
            }
            return 0;   // Tabelle (noch) nicht vorhanden → 0 statt 500
        }
    }
}
