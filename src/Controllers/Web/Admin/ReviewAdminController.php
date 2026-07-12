<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminRoleService;
use App\Game\Admin\GameAuditService;
use App\Game\Admin\GameModerationService;
use App\Game\Admin\ReviewQueueService;
use App\Http\Request;
use App\Http\Response;

/**
 * Einheitliche Review-Queue (`/admin/review`, GameAdmin_Concept.md Modul D):
 * offene UGC-Meldungen + Heuristik-Signale (High-Volume-Rider) an einer Stelle,
 * mit Statuswechsel. Rechte: `review.view` (lesen), `review.act` (Status setzen).
 */
final class ReviewAdminController
{
    use AdminAuthTrait;

    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminRoleService $roles,
        private readonly ReviewQueueService $queue,
        private readonly GameModerationService $moderation,
        private readonly GameAuditService $audit,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        [$user, , $role] = $this->requirePermission('review.view');
        $this->view->render('admin/review/index', [
            '_title' => 'Admin · Review', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'role' => $role,
            'reports' => $this->queue->openReports(100, 0),
            'highVolume' => $this->moderation->highVolumeRiders(50),
        ]);
    }

    public function resolveReport(Request $req): void
    {
        [, $adminId] = $this->requirePermission('review.act');
        $id = (int)($req->routeParams['id'] ?? 0);
        $status = trim((string)$req->input('status', 'reviewed'));
        if (!$this->queue->setReportStatus($id, $status, $adminId)) {
            $this->flash('Ungültiger Status.');
            Response::redirect('/admin/review');
        }
        $this->audit->record($adminId, 'report_' . $status, 'report#' . $id);
        $this->flash("Meldung #{$id} → {$status}.");
        Response::redirect('/admin/review');
    }
}
