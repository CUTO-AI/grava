<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminPermissions;
use App\Game\Admin\AdminRoleService;
use App\Game\Admin\GameAuditService;
use App\Http\Request;
use App\Http\Response;

/**
 * Rollen-Verwaltung (`/admin/roles`) — Backoffice Phase 0, RBAC. Vergibt
 * operator/support/analyst an konkrete User (super kommt aus ADMIN_EMAILS).
 * Erfordert das Recht `roles.manage` (nur super).
 */
final class RolesAdminController
{
    use AdminAuthTrait;

    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminRoleService $roles,
        private readonly GameAuditService $audit,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        [$user] = $this->requirePermission('roles.manage');
        $this->view->render('admin/roles/index', [
            '_title' => 'Admin · Rollen', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'assignable' => array_values(array_filter(
                AdminPermissions::ROLES, static fn($r) => $r !== 'super',
            )),
            'rows' => $this->roles->list(),
        ]);
    }

    public function assign(Request $req): void
    {
        [, $adminId] = $this->requirePermission('roles.manage');
        $ident = trim((string)$req->input('user', ''));
        $role  = trim((string)$req->input('role', ''));

        $target = $this->roles->findUser($ident);
        if ($target === null) {
            $this->flash("Kein User gefunden für: {$ident}");
            Response::redirect('/admin/roles');
        }
        if (!$this->roles->setRole((int)$target['id'], $role)) {
            $this->flash("Rolle ungültig (super wird über ADMIN_EMAILS gesetzt): {$role}");
            Response::redirect('/admin/roles');
        }
        $this->audit->record($adminId, 'role_assign', (string)$target['email'], ['role' => $role]);
        $this->flash("Rolle „{$role}\" an {$target['email']} vergeben.");
        Response::redirect('/admin/roles');
    }

    public function revoke(Request $req): void
    {
        [, $adminId] = $this->requirePermission('roles.manage');
        $userId = (int)($req->routeParams['user_id'] ?? 0);
        $this->roles->removeRole($userId);
        $this->audit->record($adminId, 'role_revoke', (string)$userId);
        $this->flash('Rolle entfernt.');
        Response::redirect('/admin/roles');
    }
}
