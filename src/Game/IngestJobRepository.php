<?php
declare(strict_types=1);

namespace App\Game;

use PDO;

/**
 * Persistenz der asynchronen Ingest-Jobs (`game_ingest_jobs`, Migration 0054).
 * Der Endpoint reiht ein (enqueue), der Cron-Worker (game:ingest-run) klaut sich
 * je Tick den nächsten queued-Job (claimNext) und schreibt Ergebnis/Fehler
 * zurück. Reines Lesen/Schreiben — die Ingest-Logik liegt im {@see IngestJobRunner}.
 */
final class IngestJobRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Legt einen Job für die Route an ODER setzt den vorhandenen (UNIQUE route_id)
     * auf `queued` zurück. Der `id = LAST_INSERT_ID(id)`-Trick sorgt dafür, dass
     * lastInsertId() in beiden Fällen die Job-ID liefert.
     */
    public function enqueue(int $routeId, int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_ingest_jobs (route_id, user_id, status)
                 VALUES (?, ?, \'queued\')
             ON DUPLICATE KEY UPDATE
                 id            = LAST_INSERT_ID(id),
                 user_id       = VALUES(user_id),
                 status        = \'queued\',
                 summary_json  = NULL,
                 error_code    = NULL,
                 error_message = NULL,
                 attempts      = 0,
                 started_at    = NULL,
                 finished_at   = NULL'
        );
        $stmt->bindValue(1, $routeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $userId, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_ingest_jobs WHERE id = ?');
        $stmt->bindValue(1, $jobId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Reserviert atomar den nächsten wartenden Job und markiert ihn `running`.
     * `FOR UPDATE SKIP LOCKED` (MySQL 8) erlaubt mehrere parallele Worker ohne
     * doppelte Zustellung. Gibt null zurück, wenn die Queue leer ist.
     *
     * @return array<string,mixed>|null
     */
    public function claimNext(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $sel = $this->pdo->query(
                'SELECT id, route_id, user_id FROM game_ingest_jobs
                  WHERE status = \'queued\'
                  ORDER BY id
                  LIMIT 1
                  FOR UPDATE SKIP LOCKED'
            );
            $job = $sel->fetch(PDO::FETCH_ASSOC);
            if ($job === false) {
                $this->pdo->commit();
                return null;
            }
            $upd = $this->pdo->prepare(
                'UPDATE game_ingest_jobs
                    SET status = \'running\', attempts = attempts + 1,
                        started_at = CURRENT_TIMESTAMP(3), finished_at = NULL
                  WHERE id = ?'
            );
            $upd->bindValue(1, (int)$job['id'], PDO::PARAM_INT);
            $upd->execute();
            $this->pdo->commit();
            return $job;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function markDone(int $jobId, string $summaryJson): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE game_ingest_jobs
                SET status = \'done\', summary_json = ?, error_code = NULL,
                    error_message = NULL, finished_at = CURRENT_TIMESTAMP(3)
              WHERE id = ?'
        );
        $stmt->bindValue(1, $summaryJson);
        $stmt->bindValue(2, $jobId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function markFailed(int $jobId, string $errorCode, string $errorMessage): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE game_ingest_jobs
                SET status = \'failed\', error_code = ?, error_message = ?,
                    finished_at = CURRENT_TIMESTAMP(3)
              WHERE id = ?'
        );
        $stmt->bindValue(1, $errorCode);
        $stmt->bindValue(2, $errorMessage);
        $stmt->bindValue(3, $jobId, PDO::PARAM_INT);
        $stmt->execute();
    }
}
