<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminRoleService;
use App\Game\Admin\DashboardAdminService;
use App\Http\Request;

/**
 * Backoffice-Startseite (`/admin`, GameAdmin_Concept.md Modul A): Live-KPIs +
 * Verknüpfungen in die Module. Erfordert `dashboard.view`.
 */
final class DashboardAdminController
{
    use AdminAuthTrait;

    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminRoleService $roles,
        private readonly DashboardAdminService $dashboard,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        [$user, , $role] = $this->requirePermission('dashboard.view');
        $this->view->render('admin/dashboard', [
            '_title' => 'Admin · Übersicht', '_authedUser' => $user, '_layoutWide' => true,
            'role' => $role,
            'm' => $this->dashboard->metrics(),
        ]);
    }
}
