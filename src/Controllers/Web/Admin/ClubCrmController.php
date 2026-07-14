<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Config\Config;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminGuard;
use App\Game\Crew\CrewService;
use App\Growth\ClubProspectRepository;
use App\Growth\SupporterAccountingService;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Mail\MailService;
use App\Support\Clock;

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
        private readonly CrewService $crews,
        private readonly MailService $mail,
        private readonly SupporterAccountingService $supporter,
        private readonly Config $appConfig,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    /** GET /admin/crm/supporter — read-only Supporter-Ökonomie-Messung (A8). */
    public function supporter(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $period = trim((string)($req->query['period'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $period) !== 1) {
            $period = gmdate('Y-m');
        }
        $this->view->render('admin/crm/supporter', [
            '_title'      => 'Supporter-Ökonomie',
            '_authedUser' => $user,
            '_layoutWide' => true,
            'flash'       => $this->takeFlash(),
            'period'      => $period,
            'rows'        => $this->supporter->report($period),
        ]);
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

    /** POST /admin/crm/{id}/invite — Aktivierungslink minten + per Mail versenden. */
    public function invite(Request $req): void
    {
        $this->requireAdmin();
        $id = (int)($req->routeParams['id'] ?? 0);
        $p = $this->prospects->byId($id);
        if ($p === null) {
            Response::error('not_found', 'Nicht gefunden.', 404);
        }
        $email = trim((string)($p['contact_email'] ?? ''));
        if ($email === '') {
            $this->flash('Keine Kontakt-E-Mail hinterlegt — bitte zuerst ergänzen.');
            Response::redirect('/admin/crm');
        }
        $name = (string)$p['name'];
        $token = $this->crews->issueVerifyInvite([
            'display_name'        => mb_substr($name, 0, 40),
            'org_name'            => $name,
            'register_court'      => $p['register_court'] ?? null,
            'register_no'         => $p['register_no'] ?? null,
            'is_charitable'       => (int)($p['is_charitable'] ?? 0) === 1,
            'official_source_url' => $p['official_source_url'] ?? null,
            'contact_email'       => $email,
            'membership_url'      => null,
        ]);
        $this->prospects->setInvited($id, $token, Clock::nowUtc()->format('Y-m-d H:i:s.v'));
        $activateUrl = 'https://cyberride.world/verein-aktivieren/' . $token;

        $mailHost = trim((string)$this->appConfig->get('MAIL_HOST', ''));
        if ($mailHost === '') {
            // MAIL_HOST leer → MailService würde nur auf Platte schreiben (kein
            // echter Versand). Ehrlich melden + Link zum manuellen Teilen geben.
            $this->flash('⚠️ Mailversand ist nicht konfiguriert (MAIL_HOST leer) — es wurde KEINE E-Mail gesendet. Aktivierungslink manuell teilen: ' . $activateUrl);
            Response::redirect('/admin/crm');
        }
        $sent = $this->mail->send($email, $name, 'club_verify_invite', [
            'org_name'     => $name,
            'activate_url' => $activateUrl,
            'app_name'     => 'CYBERRIDE',
        ]);
        $this->flash($sent
            ? ('Einladung gesendet an ' . $email . '. Aktivierungslink: ' . $activateUrl)
            : ('⚠️ Mailversand fehlgeschlagen (SMTP) — bitte Log prüfen. Aktivierungslink manuell: ' . $activateUrl));
        Response::redirect('/admin/crm');
    }

    /** POST /admin/crm/import — CSV-Batch-Import (Upsert/Dedup); `dryrun` = nur Vorschau. */
    public function importCsv(Request $req): void
    {
        $this->requireAdmin();
        $raw = trim((string)$req->input('csv', ''));
        $dry = $req->input('dryrun', null) !== null;
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        if (count($lines) < 2) {
            $this->flash('CSV leer oder ohne Datenzeilen (Kopfzeile + mind. 1 Zeile erwartet).');
            Response::redirect('/admin/crm');
        }
        $allowed = ['name', 'landkreis', 'discipline', 'contact_email', 'official_source_url', 'register_court', 'register_no', 'is_charitable'];
        $header = array_map('trim', str_getcsv((string)array_shift($lines)));
        $ok = 0; $skipped = 0;
        foreach ($lines as $ln) {
            if (trim($ln) === '') {
                continue;
            }
            $cells = str_getcsv($ln);
            $row = [];
            foreach ($header as $i => $col) {
                if (in_array($col, $allowed, true)) {
                    $row[$col] = trim((string)($cells[$i] ?? ''));
                }
            }
            if (($row['name'] ?? '') === '') {
                $skipped++;
                continue;
            }
            if (isset($row['is_charitable'])) {
                $row['is_charitable'] = in_array(mb_strtolower((string)$row['is_charitable']), ['1', 'true', 'ja', 'yes', 'x'], true);
            }
            if (!$dry) {
                $this->prospects->upsert($row);
            }
            $ok++;
        }
        $this->flash(($dry ? '[Vorschau] ' : '') . "{$ok} Zeilen ok, {$skipped} übersprungen (ohne Name).");
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
