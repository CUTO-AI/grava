<?php
declare(strict_types=1);

namespace App\Engagement;

use App\Database\Db;

/**
 * Meldungen zu anstößigen Inhalten (App-Store-Richtlinie 1.2, UGC).
 *
 * Nutzer können Kommentare, Routen und andere Nutzer melden. Meldungen landen
 * in `content_report` (Status „open") und werden manuell gesichtet. Routen-/
 * Kommentar-Sichtbarkeit wird über {@see RouteVisibility} geprüft (404 bei
 * unsichtbar/blockiert), Nutzer per Handle aufgelöst — analog SocialController.
 *
 * Wirft {@see EngagementException} (error_code + http_status) für die Controller.
 */
final class ReportService
{
    private const REASONS = ['spam', 'abuse', 'harassment', 'explicit', 'other'];

    public function reportRoute(string $routePublicId, int $reporterId, string $reason, ?string $description): void
    {
        $route = RouteVisibility::resolveVisibleOrThrow($routePublicId, $reporterId);
        $this->insert($reporterId, 'route', $route['route_id'], $reason, $description);
    }

    public function reportComment(string $routePublicId, int $commentId, int $reporterId, string $reason, ?string $description): void
    {
        $route = RouteVisibility::resolveVisibleOrThrow($routePublicId, $reporterId);
        $stmt = Db::pdo()->prepare(
            'SELECT id FROM route_comments WHERE id = ? AND route_id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([$commentId, $route['route_id']]);
        if ($stmt->fetchColumn() === false) {
            throw new EngagementException('not_found', 'Kommentar existiert nicht.', 404);
        }
        $this->insert($reporterId, 'comment', $commentId, $reason, $description);
    }

    public function reportUser(string $handle, int $reporterId, string $reason, ?string $description): void
    {
        if ($handle === '' || preg_match('/^[a-z0-9_]{2,30}$/', $handle) !== 1) {
            throw new EngagementException('not_found', 'Profil existiert nicht.', 404);
        }
        $stmt = Db::pdo()->prepare("SELECT id FROM users WHERE public_handle = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$handle]);
        $tid = $stmt->fetchColumn();
        if ($tid === false) {
            throw new EngagementException('not_found', 'Profil existiert nicht.', 404);
        }
        $tid = (int)$tid;
        if ($tid === $reporterId) {
            throw new EngagementException('validation_error', 'Du kannst dich nicht selbst melden.', 422);
        }
        $this->insert($reporterId, 'user', $tid, $reason, $description);
    }

    private function insert(int $reporterId, string $type, int $contentId, string $reason, ?string $description): void
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new EngagementException('validation_error', 'Ungültiger Meldegrund.', 422);
        }
        $desc = $description !== null ? mb_substr(trim($description), 0, 500) : null;
        if ($desc === '') {
            $desc = null;
        }
        // Idempotent: erneutes Melden desselben Inhalts aktualisiert nur Grund/Text
        // und hält den Fall offen (außer er wurde bereits „resolved").
        $stmt = Db::pdo()->prepare(
            'INSERT INTO content_report (reporter_id, content_type, content_id, reason, description)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                reason = VALUES(reason),
                description = VALUES(description),
                status = IF(status = "resolved", status, "open")'
        );
        $stmt->execute([$reporterId, $type, $contentId, $reason, $desc]);
    }
}
