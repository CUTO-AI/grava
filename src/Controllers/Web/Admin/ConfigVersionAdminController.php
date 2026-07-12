<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminRoleService;
use App\Game\Admin\GameAuditService;
use App\Game\Admin\GameConfigAdminService;
use App\Game\Admin\GameConfigVersionService;
use App\Http\Request;
use App\Http\Response;

/**
 * Config-Versionierung (`/admin/config/versions`, GameAdmin_Concept.md Phase 2):
 * Historie der Voll-Snapshots, Diff zur Vorversion und Rollback (Snapshot erneut
 * anwenden). Rechte: `config.view` (lesen), `config.write` (Rollback).
 */
final class ConfigVersionAdminController
{
    use AdminAuthTrait;

    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminRoleService $roles,
        private readonly GameConfigVersionService $versions,
        private readonly GameConfigAdminService $configAdmin,
        private readonly GameAuditService $audit,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        [$user, , $role] = $this->requirePermission('config.view');
        $this->view->render('admin/config/versions', [
            '_title' => 'Admin · Config-Versionen', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'role' => $role,
            'versions' => $this->versions->listVersions(80),
        ]);
    }

    public function show(Request $req): void
    {
        [$user, , $role] = $this->requirePermission('config.view');
        $id = (int)($req->routeParams['id'] ?? 0);
        $version = $this->versions->get($id);
        if ($version === null) {
            $this->flash('Version nicht gefunden.');
            Response::redirect('/admin/config/versions');
        }
        $this->view->render('admin/config/version_diff', [
            '_title' => 'Admin · Config-Diff', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'role' => $role,
            'version' => $version,
            'diff' => $this->versions->diffToPrevious($id),
        ]);
    }

    public function restore(Request $req): void
    {
        [, $adminId] = $this->requirePermission('config.write');
        $id = (int)($req->routeParams['id'] ?? 0);
        $version = $this->versions->get($id);
        if ($version === null) {
            $this->flash('Version nicht gefunden.');
            Response::redirect('/admin/config/versions');
        }
        $errors = $this->configAdmin->update($adminId, $version['values'], 'rollback zu v#' . $id);
        if ($errors !== []) {
            $this->flash('Rollback fehlgeschlagen (Validierung): ' . implode(', ', array_keys($errors)));
            Response::redirect('/admin/config/versions/' . $id);
        }
        $this->audit->record($adminId, 'config_rollback', 'config_version:' . $id);
        $this->flash("Config auf Version #{$id} zurückgesetzt.");
        Response::redirect('/admin/config/versions');
    }
}
