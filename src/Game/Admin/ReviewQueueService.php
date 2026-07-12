<?php
declare(strict_types=1);

namespace App\Game\Admin;

use App\Support\Clock;
use PDO;

/**
 * Review-Queue fürs Backoffice (GameAdmin_Concept.md Modul D): offene UGC-
 * Meldungen (`content_report`) mit Statuswechsel. Heuristik-Signale (High-Volume-
 * Rider) kommen aus {@see GameModerationService}; suspiciousSpeed ist mangels
 * gespeicherter Pass-Geschwindigkeit noch leer (dort dokumentiert).
 */
final class ReviewQueueService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string,mixed>> */
    public function openReports(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.content_type, r.content_id, r.reason, r.description,
                    r.status, r.created_at, ru.email AS reporter_email
               FROM content_report r
               LEFT JOIN users ru ON ru.id = r.reporter_id
              WHERE r.status = ?
              ORDER BY r.created_at ASC
              LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, 'open');
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(3, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function openReportCount(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM content_report WHERE status = 'open'")->fetchColumn();
    }

    /** Setzt den Status einer Meldung (reviewed/resolved/open) + Prüfer/Zeitpunkt. */
    public function setReportStatus(int $reportId, string $status, int $adminUserId): bool
    {
        if (!in_array($status, ['open', 'reviewed', 'resolved'], true)) {
            return false;
        }
        $this->pdo->prepare(
            'UPDATE content_report
                SET status = ?, reviewed_at = ?, reviewed_by = ?
              WHERE id = ?'
        )->execute([$status, Clock::nowUtcString(), $adminUserId, $reportId]);
        return true;
    }
}
