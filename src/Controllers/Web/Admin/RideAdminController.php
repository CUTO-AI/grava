<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminRoleService;
use App\Game\Admin\GameAuditService;
use App\Game\Admin\RideAdminService;
use App\Game\IngestJobRepository;
use App\Http\Request;
use App\Http\Response;

/**
 * Fahrten-/Routen-Verwaltung (`/admin/rides`, GameAdmin_Concept.md Modul C):
 * Liste + Detail + Aktionen (re-ingest über die async Queue, für Spiel
 * invalidieren, verbergen). Rechte: `ride.view`, `ride.reingest`, `ride.invalidate`,
 * `ride.delete`.
 */
final class RideAdminController
{
    use AdminAuthTrait;

    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminRoleService $roles,
        private readonly RideAdminService $rides,
        private readonly IngestJobRepository $ingestJobs,
        private readonly GameAuditService $audit,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        [$user, , $role] = $this->requirePermission('ride.view');
        $lq = AdminListQuery::fromRequest($req, RideAdminService::SORTS, 'created_at');
        $source = trim((string)($req->query['source'] ?? ''));
        $userId = isset($req->query['user_id']) && $req->query['user_id'] !== ''
            ? (int)$req->query['user_id'] : null;
        $rows = $this->rides->search($lq->q, $source, $lq->sort, $lq->dir, $lq->perPage, $lq->offset, $userId);
        $this->view->render('admin/rides/index', [
            '_title' => 'Admin · Fahrten', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'role' => $role,
            'rows' => $rows,
            'lq' => $lq,
            'source' => $source,
            'userId' => $userId,
            'hasMore' => $lq->hasMore(count($rows)),
        ]);
    }

    public function show(Request $req): void
    {
        [$user, , $role] = $this->requirePermission('ride.view');
        $id = (int)($req->routeParams['id'] ?? 0);
        $detail = $this->rides->detail($id);
        if ($detail === null) {
            $this->flash('Fahrt nicht gefunden.');
            Response::redirect('/admin/rides');
        }
        $this->view->render('admin/rides/show', [
            '_title' => 'Admin · Fahrt', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'role' => $role,
            'd' => $detail,
        ]);
    }

    public function reingest(Request $req): void
    {
        [, $adminId] = $this->requirePermission('ride.reingest');
        $id = (int)($req->routeParams['id'] ?? 0);
        $owner = $this->rides->owner($id);
        if ($owner === null) {
            $this->flash('Fahrt nicht gefunden.');
            Response::redirect('/admin/rides');
        }
        $jobId = $this->ingestJobs->enqueue($id, $owner['user_id']);
        $this->audit->record($adminId, 'ride_reingest', (string)$id, ['job_id' => $jobId]);
        $this->flash("Re-Ingest eingereiht (Job #{$jobId}). Verarbeitung läuft im Hintergrund.");
        Response::redirect('/admin/rides/' . $id);
    }

    public function invalidate(Request $req): void
    {
        [, $adminId] = $this->requirePermission('ride.invalidate');
        $id = (int)($req->routeParams['id'] ?? 0);
        $reason = trim((string)$req->input('reason', '')) ?: 'Admin-Invalidierung';
        $n = $this->rides->invalidateGame($id, $adminId, $reason);
        $this->audit->record($adminId, 'ride_invalidate', (string)$id, ['passes' => $n, 'reason' => $reason]);
        $this->flash("{$n} Pässe invalidiert (aus dem Spiel entfernt).");
        Response::redirect('/admin/rides/' . $id);
    }

    public function delete(Request $req): void
    {
        [, $adminId] = $this->requirePermission('ride.delete');
        $id = (int)($req->routeParams['id'] ?? 0);
        $this->rides->softDelete($id);
        $this->audit->record($adminId, 'ride_delete', (string)$id);
        $this->flash('Fahrt verborgen (soft-deleted).');
        Response::redirect('/admin/rides');
    }
}
