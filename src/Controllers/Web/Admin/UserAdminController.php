<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminRoleService;
use App\Game\Admin\GameAuditService;
use App\Game\Admin\GameUserFlagService;
use App\Game\Admin\UserAdminService;
use App\Http\Request;
use App\Http\Response;

/**
 * Nutzerverwaltung (`/admin/users`, GameAdmin_Concept.md Modul B): Liste + 360°-
 * Detail + Support-Aktionen. Rechte: `user.view` (lesen), `user.ban`, `user.support`
 * (Verify), `user.edit` (Profil), destruktiv (Anonymisieren) nur super (`user.delete`).
 */
final class UserAdminController
{
    use AdminAuthTrait;

    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminRoleService $roles,
        private readonly UserAdminService $users,
        private readonly GameUserFlagService $flags,
        private readonly GameAuditService $audit,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        [$user, , $role] = $this->requirePermission('user.view');
        $lq = AdminListQuery::fromRequest($req, UserAdminService::SORTS, 'created_at');
        $rows = $this->users->search($lq->q, $lq->sort, $lq->dir, $lq->perPage, $lq->offset);
        $this->view->render('admin/users/index', [
            '_title' => 'Admin · User', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'role' => $role,
            'rows' => $rows,
            'lq' => $lq,
            'hasMore' => $lq->hasMore(count($rows)),
        ]);
    }

    public function show(Request $req): void
    {
        [$user, , $role] = $this->requirePermission('user.view');
        $id = (int)($req->routeParams['id'] ?? 0);
        $detail = $this->users->detail($id);
        if ($detail === null) {
            $this->flash('User nicht gefunden.');
            Response::redirect('/admin/users');
        }
        $this->view->render('admin/users/show', [
            '_title' => 'Admin · User', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'role' => $role,
            'd' => $detail,
            'auditRows' => $this->audit->forTarget((string)$id, 20),
        ]);
    }

    public function ban(Request $req): void
    {
        [, $adminId] = $this->requirePermission('user.ban');
        $id = (int)($req->routeParams['id'] ?? 0);
        $reason = trim((string)$req->input('reason', ''));
        $this->flags->ban($adminId, $id, $reason !== '' ? $reason : 'Admin-Ban');
        $this->audit->record($adminId, 'user_ban', (string)$id, ['reason' => $reason]);
        $this->flash('User gebannt.');
        Response::redirect('/admin/users/' . $id);
    }

    public function unban(Request $req): void
    {
        [, $adminId] = $this->requirePermission('user.ban');
        $id = (int)($req->routeParams['id'] ?? 0);
        $this->flags->unban($adminId, $id);
        $this->audit->record($adminId, 'user_unban', (string)$id);
        $this->flash('Ban aufgehoben.');
        Response::redirect('/admin/users/' . $id);
    }

    public function verify(Request $req): void
    {
        [, $adminId] = $this->requirePermission('user.support');
        $id = (int)($req->routeParams['id'] ?? 0);
        $this->users->forceVerify($id);
        $this->audit->record($adminId, 'user_force_verify', (string)$id);
        $this->flash('E-Mail als verifiziert markiert.');
        Response::redirect('/admin/users/' . $id);
    }

    public function profile(Request $req): void
    {
        [, $adminId] = $this->requirePermission('user.edit');
        $id = (int)($req->routeParams['id'] ?? 0);
        $displayName = trim((string)$req->input('display_name', ''));
        $handle = trim((string)$req->input('handle', ''));
        if (!$this->users->setProfile($id, $displayName, $handle)) {
            $this->flash('Handle bereits vergeben.');
            Response::redirect('/admin/users/' . $id);
        }
        $this->audit->record($adminId, 'user_edit_profile', (string)$id, ['display_name' => $displayName, 'handle' => $handle]);
        $this->flash('Profil aktualisiert.');
        Response::redirect('/admin/users/' . $id);
    }

    public function anonymize(Request $req): void
    {
        // user.delete steht nur super zu (nicht in der operator-Matrix).
        [, $adminId] = $this->requirePermission('user.delete');
        $id = (int)($req->routeParams['id'] ?? 0);
        $this->users->anonymize($id);
        $this->audit->record($adminId, 'user_anonymize', (string)$id);
        $this->flash('User anonymisiert (DSGVO).');
        Response::redirect('/admin/users/' . $id);
    }
}
