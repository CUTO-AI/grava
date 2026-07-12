<?php
declare(strict_types=1);

namespace App\Cli;

use App\Support\Clock;
use PDO;
use PDOException;

/**
 * Persistenz der Cron-Läufe (`cron_runs`, Migration 0055). Schreibpfad wird vom
 * Wrapper {@see Commands::run()} bedient (Fehler dort geschluckt), Lesepfad vom
 * {@see \App\Controllers\Web\Admin\CronAdminController}. Lesende Methoden tolerieren
 * eine (noch) fehlende Tabelle (MySQL 1146) → leeres Ergebnis, damit das Dashboard
 * vor der Migration nicht 500t.
 */
final class CronRunRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // ---- Schreibpfad -------------------------------------------------------

    public function begin(string $canonical, string $trigger, string $host): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cron_runs (command, status, trigger_kind, host, started_at)
             VALUES (?, \'running\', ?, ?, ?)'
        );
        $stmt->execute([$canonical, $trigger, $host, Clock::nowUtcString()]);
        return (int)$this->pdo->lastInsertId();
    }

    public function finish(
        int $id,
        string $status,
        ?int $exitCode,
        int $durationMs,
        ?string $outputTail,
        ?string $error,
        bool $didWork,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE cron_runs
                SET status = ?, exit_code = ?, finished_at = ?, duration_ms = ?,
                    output_tail = ?, error_message = ?, did_work = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $status, $exitCode, Clock::nowUtcString(), $durationMs,
            $outputTail, $error, $didWork ? 1 : 0, $id,
        ]);
    }

    /** Vollzeile ohne vorheriges begin() (falls begin() scheiterte). */
    public function insertCompleted(
        string $canonical,
        string $status,
        ?int $exitCode,
        string $trigger,
        string $host,
        int $durationMs,
        bool $didWork,
        ?string $outputTail,
        ?string $error,
    ): void {
        $now = Clock::nowUtcString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO cron_runs
                (command, status, exit_code, trigger_kind, host, started_at,
                 finished_at, duration_ms, did_work, output_tail, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $canonical, $status, $exitCode, $trigger, $host, $now,
            $now, $durationMs, $didWork ? 1 : 0, $outputTail, $error,
        ]);
    }

    public function deleteById(int $id): void
    {
        $this->pdo->prepare('DELETE FROM cron_runs WHERE id = ?')->execute([$id]);
    }

    /**
     * Idle-Tick (Leerlauf, ok): fasst zu EINER Heartbeat-Zeile je Befehl pro UTC-Tag
     * zusammen — hält `started_at` frisch (für Überfälligkeit), ohne die Tabelle zu
     * fluten. Aktualisiert die heutige idle-Zeile, sonst legt sie eine an.
     */
    public function recordIdle(string $canonical, string $host, int $durationMs): void
    {
        $now = Clock::nowUtcString();
        $upd = $this->pdo->prepare(
            'UPDATE cron_runs
                SET started_at = ?, finished_at = ?, duration_ms = ?, host = ?
              WHERE command = ? AND did_work = 0 AND status = \'ok\'
                AND started_at >= ?
              ORDER BY started_at DESC
              LIMIT 1'
        );
        $todayStart = substr($now, 0, 10) . ' 00:00:00.000';
        $upd->execute([$now, $now, $durationMs, $host, $canonical, $todayStart]);
        if ($upd->rowCount() > 0) {
            return;
        }
        $this->insertCompleted($canonical, 'ok', 0, 'cron', $host, $durationMs, false, null, null);
    }

    // ---- Lesepfad (Admin) --------------------------------------------------

    /** @return array<string,array<string,mixed>> command => neueste Zeile */
    public function latestPerCommand(): array
    {
        return $this->indexByCommand(
            'SELECT r.* FROM cron_runs r
               JOIN (SELECT command, MAX(started_at) AS mx FROM cron_runs GROUP BY command) t
                 ON t.command = r.command AND t.mx = r.started_at'
        );
    }

    /** @return array<string,array<string,mixed>> command => neueste ok-Zeile */
    public function lastSuccessPerCommand(): array
    {
        return $this->indexByCommand(
            'SELECT r.* FROM cron_runs r
               JOIN (SELECT command, MAX(started_at) AS mx FROM cron_runs
                      WHERE status = \'ok\' GROUP BY command) t
                 ON t.command = r.command AND t.mx = r.started_at
              WHERE r.status = \'ok\''
        );
    }

    /**
     * 24h-Aggregate je Befehl (abgeschlossene Läufe).
     * @return array<string,array{runs:int,failures:int,avg_ms:?int,max_ms:?int}>
     */
    public function aggregates24h(): array
    {
        $since = Clock::nowUtc()->modify('-24 hours')->format('Y-m-d H:i:s');
        try {
            $stmt = $this->pdo->prepare(
                'SELECT command,
                        COUNT(*) AS runs,
                        SUM(status = \'failed\') AS failures,
                        AVG(duration_ms) AS avg_ms,
                        MAX(duration_ms) AS max_ms
                   FROM cron_runs
                  WHERE started_at >= ? AND status <> \'running\'
                  GROUP BY command'
            );
            $stmt->execute([$since]);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(string)$row['command']] = [
                    'runs'     => (int)$row['runs'],
                    'failures' => (int)$row['failures'],
                    'avg_ms'   => $row['avg_ms'] !== null ? (int)round((float)$row['avg_ms']) : null,
                    'max_ms'   => $row['max_ms'] !== null ? (int)$row['max_ms'] : null,
                ];
            }
            return $out;
        } catch (PDOException $e) {
            return $this->emptyOnMissingTable($e);
        }
    }

    /** p95 der Dauer über die letzten N abgeschlossenen Läufe (in PHP berechnet). */
    public function p95Recent(string $canonical, int $n = 200): ?int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT duration_ms FROM cron_runs
                  WHERE command = ? AND status <> \'running\' AND duration_ms IS NOT NULL
                  ORDER BY started_at DESC
                  LIMIT ?'
            );
            $stmt->bindValue(1, $canonical);
            $stmt->bindValue(2, max(1, $n), PDO::PARAM_INT);
            $stmt->execute();
            $vals = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            $this->emptyOnMissingTable($e);
            return null;
        }
        if ($vals === []) {
            return null;
        }
        sort($vals);
        $idx = (int)ceil(0.95 * count($vals)) - 1;
        return $vals[max(0, min($idx, count($vals) - 1))];
    }

    /** @return list<array<string,mixed>> */
    public function history(string $canonical, int $limit, int $offset): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM cron_runs WHERE command = ?
                  ORDER BY started_at DESC LIMIT ? OFFSET ?'
            );
            $stmt->bindValue(1, $canonical);
            $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
            $stmt->bindValue(3, max(0, $offset), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return $this->emptyOnMissingTable($e);
        }
    }

    public function historyCount(string $canonical): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cron_runs WHERE command = ?');
            $stmt->execute([$canonical]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $this->emptyOnMissingTable($e);
            return 0;
        }
    }

    // ---- Wartung -----------------------------------------------------------

    /**
     * Markiert hängende Läufe (`running` älter als die erlaubte Max-Laufzeit) als
     * `failed`. `$maxByCommand` = kanonisch→Sekunden; alles andere via `$globalDefault`.
     * @param array<string,int> $maxByCommand
     * @return int Anzahl markierter Zeilen
     */
    public function sweepStuck(array $maxByCommand, int $globalDefault): int
    {
        $marked = 0;
        try {
            foreach ($maxByCommand as $command => $maxS) {
                $marked += $this->markStuckWhere(
                    'command = ? AND status = \'running\' AND started_at < (UTC_TIMESTAMP(3) - INTERVAL ? SECOND)',
                    [$command, (int)$maxS],
                    (int)$maxS,
                );
            }
            // Fallback für evtl. unbekannte Befehle (sollten nicht vorkommen).
            $known = array_keys($maxByCommand);
            $placeholders = $known === [] ? "''" : implode(',', array_fill(0, count($known), '?'));
            $marked += $this->markStuckWhere(
                "command NOT IN ($placeholders) AND status = 'running' AND started_at < (UTC_TIMESTAMP(3) - INTERVAL ? SECOND)",
                [...$known, $globalDefault],
                $globalDefault,
            );
            return $marked;
        } catch (PDOException $e) {
            $this->emptyOnMissingTable($e);
            return $marked;
        }
    }

    public function pruneOlderThan(int $days): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'DELETE FROM cron_runs WHERE started_at < (UTC_TIMESTAMP(3) - INTERVAL ? DAY)'
            );
            $stmt->bindValue(1, max(1, $days), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->emptyOnMissingTable($e);
            return 0;
        }
    }

    // ---- intern ------------------------------------------------------------

    /** @param list<mixed> $params */
    private function markStuckWhere(string $where, array $params, int $maxS): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE cron_runs
                SET status = 'failed',
                    finished_at = UTC_TIMESTAMP(3),
                    duration_ms = TIMESTAMPDIFF(MICROSECOND, started_at, UTC_TIMESTAMP(3)) / 1000,
                    error_message = CONCAT('stuck: running > max_runtime (', {$maxS}, 's)')
              WHERE {$where}"
        );
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** @return array<string,array<string,mixed>> */
    private function indexByCommand(string $sql): array
    {
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $row) {
                $out[(string)$row['command']] = $row;
            }
            return $out;
        } catch (PDOException $e) {
            return $this->emptyOnMissingTable($e);
        }
    }

    /** @return array<never,never> */
    private function emptyOnMissingTable(PDOException $e): array
    {
        if (!str_contains($e->getMessage(), '1146')) {
            throw $e;
        }
        return [];
    }
}
