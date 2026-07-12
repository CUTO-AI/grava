<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminRoleService;
use App\Game\Admin\CommunityAdminService;
use App\Game\Admin\GameAuditService;
use App\Http\Request;
use App\Http\Response;

/**
 * Konsolidierte Community-Verwaltung (`/admin/community`, GameAdmin_Concept.md
 * Phase 2): Crews + Fraktionen + Gebiete an einer Stelle, plus Crew-Moderation
 * (umbenennen, Logo entfernen, auflösen) und Gebiets-Recompute. Rechte:
 * `crew.manage` (Crews), `region.manage` (Gebiete). Lesen mind. `crew.manage`.
 */
final class CommunityAdminController
{
    use AdminAuthTrait;

    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminRoleService $roles,
        private readonly CommunityAdminService $community,
        private readonly GameAuditService $audit,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        [$user, , $role] = $this->requirePermission('crew.manage');
        $overview = $this->community->regionsOverview();
        $this->view->render('admin/community/index', [
            '_title' => 'Admin · Community', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'role' => $role,
            'crews' => $this->community->crewList(100),
            'factions' => $this->community->factionStandings()['factions'] ?? [],
            'regionSummary' => $overview['summary'] ?? [],
            'regionOwned' => $overview['owned'] ?? [],
        ]);
    }

    public function crew(Request $req): void
    {
        [$user, , $role] = $this->requirePermission('crew.manage');
        $id = (int)($req->routeParams['id'] ?? 0);
        $detail = $this->community->crewDetail($id);
        if ($detail === null) {
            $this->flash('Crew nicht gefunden.');
            Response::redirect('/admin/community');
        }
        $this->view->render('admin/community/crew', [
            '_title' => 'Admin · Crew', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'role' => $role,
            'd' => $detail,
        ]);
    }

    public function crewRename(Request $req): void
    {
        [, $adminId] = $this->requirePermission('crew.manage');
        $id = (int)($req->routeParams['id'] ?? 0);
        $name = trim((string)$req->input('name', ''));
        if (!$this->community->renameCrew($id, $name)) {
            $this->flash('Ungültiger Name (1–40 Zeichen).');
            Response::redirect('/admin/community/crew/' . $id);
        }
        $this->audit->record($adminId, 'crew_rename', 'crew:' . $id, ['name' => $name]);
        $this->flash('Crew umbenannt.');
        Response::redirect('/admin/community/crew/' . $id);
    }

    public function crewClearLogo(Request $req): void
    {
        [, $adminId] = $this->requirePermission('crew.manage');
        $id = (int)($req->routeParams['id'] ?? 0);
        $this->community->clearCrewLogo($id);
        $this->audit->record($adminId, 'crew_clear_logo', 'crew:' . $id);
        $this->flash('Crew-Logo entfernt.');
        Response::redirect('/admin/community/crew/' . $id);
    }

    public function crewDissolve(Request $req): void
    {
        [, $adminId] = $this->requirePermission('crew.manage');
        $id = (int)($req->routeParams['id'] ?? 0);
        if (!$this->community->dissolveCrew($id)) {
            $this->flash('Crew nicht gefunden.');
            Response::redirect('/admin/community');
        }
        $this->audit->record($adminId, 'crew_dissolve', 'crew:' . $id);
        $this->flash('Crew aufgelöst (Territorium freigegeben, Besitz neu berechnet).');
        Response::redirect('/admin/community');
    }

    public function regionsRecompute(Request $req): void
    {
        [, $adminId] = $this->requirePermission('region.manage');
        $changes = $this->community->recomputeRegions();
        $this->audit->record($adminId, 'regions_recompute', null, ['changes' => $changes]);
        $this->flash("Gebiets-Besitz neu berechnet ({$changes} Änderungen).");
        Response::redirect('/admin/community');
    }
}
