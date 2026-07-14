<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminGuard;
use App\Growth\ClubProspectRepository;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;

/**
 * Vereins-CRM / Outreach-Backoffice (CrewInvite_Onboarding_Spec §8.3, Phase 3).
 * Server-gerendert; Schutz wie das Game-Admin (WebSession + ADMIN_EMAILS).
 *
 * Erste Stufe: Liste/Board + Eingabemaske (Einzelpflege) + Statuspflege.
 * Folge-Schritte (eigene Commits): CSV-Batch-Import, E-Mail-Versand, Link-/
 * E-Mail-Öffnungs-Tracking, Funnel-Auswertung.
 */
final class ClubCrmController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminGuard $guard,
        private readonly ClubProspectRepository $prospects,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    /** GET /admin/crm — Liste + Funnel-Zählung + Eingabemaske. */
    public function index(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $status = trim((string)($req->query['status'] ?? ''));
        $landkreis = trim((string)($req->query['landkreis'] ?? ''));
        $this->view->render('admin/crm/index', [
            '_title'      => 'Vereins-CRM',
            '_authedUser' => $user,
            '_layoutWide' => true,
            'flash'       => $this->takeFlash(),
            'prospects'   => $this->prospects->list($status !== '' ? $status : null, $landkreis !== '' ? $landkreis : null),
            'counts'      => $this->prospects->statusCounts(),
            'statuses'    => ClubProspectRepository::STATUSES,
            'filterStatus'=> $status,
            'filterLandkreis' => $landkreis,
        ]);
    }

    /** POST /admin/crm — neuen Verein anlegen (Eingabemaske). */
    public function create(Request $req): void
    {
        $this->requireAdmin();
        $name = trim((string)$req->input('name', ''));
        if ($name === '') {
            $this->flash('Name ist erforderlich.');
            Response::redirect('/admin/crm');
        }
        $this->prospects->upsert($this->formData($req, includeStatus: false));
        $this->flash('Verein gespeichert.');
        Response::redirect('/admin/crm');
    }

    /** POST /admin/crm/{id} — bestehenden Verein aktualisieren (Status/Felder/Notizen). */
    public function update(Request $req): void
    {
        $this->requireAdmin();
        $id = (int)($req->routeParams['id'] ?? 0);
        if ($id <= 0 || $this->prospects->byId($id) === null) {
            Response::error('not_found', 'Nicht gefunden.', 404);
        }
        $this->prospects->update($id, $this->formData($req, includeStatus: true));
        $this->flash('Aktualisiert.');
        Response::redirect('/admin/crm');
    }

    /** @return array<string,mixed> */
    private function formData(Request $req, bool $includeStatus): array
    {
        $data = [
            'name'                => trim((string)$req->input('name', '')),
            'landkreis'           => trim((string)$req->input('landkreis', '')) ?: null,
            'discipline'          => trim((string)$req->input('discipline', '')) ?: null,
            'contact_email'       => trim((string)$req->input('contact_email', '')) ?: null,
            'official_source_url' => trim((string)$req->input('official_source_url', '')) ?: null,
            'register_court'      => trim((string)$req->input('register_court', '')) ?: null,
            'register_no'         => trim((string)$req->input('register_no', '')) ?: null,
            'is_charitable'       => $req->input('is_charitable', null) !== null,
            'assigned_to'         => trim((string)$req->input('assigned_to', '')) ?: null,
            'notes'               => trim((string)$req->input('notes', '')) ?: null,
        ];
        if ($includeStatus) {
            $status = trim((string)$req->input('status', ''));
            if (in_array($status, ClubProspectRepository::STATUSES, true)) {
                $data['status'] = $status;
            }
        }
        return $data;
    }

    /** @return array{0:array<string,mixed>,1:int} */
    private function requireAdmin(): array
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/login');
        }
        $user = $this->auth->loadUserPublic($ctx['user_id']);
        if (!$this->guard->isAdminEmail((string)($user['email'] ?? ''))) {
            Response::error('not_found', 'Nicht gefunden.', 404);
        }
        return [$user, (int)$ctx['user_id']];
    }

    private function flash(string $msg): void
    {
        Csrf::ensureStarted();
        $_SESSION['flash'] = $msg;
    }

    private function takeFlash(): ?string
    {
        Csrf::ensureStarted();
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $f !== null ? (string)$f : null;
    }
}
