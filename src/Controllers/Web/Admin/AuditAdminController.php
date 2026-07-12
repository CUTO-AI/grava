<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminRoleService;
use App\Game\Admin\GameAuditService;
use App\Http\Request;
use App\Http\Response;

/**
 * Durchsuchbare Audit-Sicht (`/admin/audit`) — Backoffice Phase 0. Zeigt die
 * protokollierten Admin-Aktionen mit Filter (Admin-E-Mail, Aktion, Zeitraum).
 * Erfordert das Recht `audit.view`.
 */
final class AuditAdminController
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
        [$user] = $this->requirePermission('audit.view');

        $adminEmail = trim((string)($req->query['admin'] ?? ''));
        $action     = trim((string)($req->query['action'] ?? ''));
        $since      = trim((string)($req->query['since'] ?? ''));   // 'YYYY-MM-DD'
        $perPage    = 50;
        $page       = max(1, (int)($req->query['page'] ?? 1));
        $offset     = ($page - 1) * $perPage;

        $rows = $this->audit->search(
            $adminEmail !== '' ? $adminEmail : null,
            $action !== '' ? $action : null,
            $since !== '' ? $since . ' 00:00:00' : null,
            $perPage,
            $offset,
        );

        $this->view->render('admin/audit/index', [
            '_title' => 'Admin · Audit', '_authedUser' => $user, '_layoutWide' => true,
            'rows' => $rows,
            'filter' => ['admin' => $adminEmail, 'action' => $action, 'since' => $since],
            'page' => $page,
            'hasMore' => count($rows) >= $perPage,
        ]);
    }
}
