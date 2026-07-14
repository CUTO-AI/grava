<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\CookieAuth;
use App\Auth\WebSession;
use App\Config\Config;
use App\Game\Crew\CrewService;
use App\Growth\ClubProspectRepository;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Support\Clock;

/**
 * Vereins-Web-Journey (CrewInvite_Onboarding_Spec §10):
 *   - GET /c/{code}                    → Mitglieder-Einladungs-Landing (Phase 1)
 *   - GET /verein-aktivieren/{token}   → Info + Aktivierungs-Formular (vorbelegt)
 *   - POST /verein-aktivieren/{token}  → Konto anlegen/verifizieren + Verein aktivieren + CRM-Link
 *   - GET /verein                      → Captain-Cockpit (Mitglieder, Invite-Link)
 */
final class CrewPagesController
{
    private readonly WebView $view;

    public function __construct(
        private readonly Config $config,
        private readonly CrewService $crews,
        private readonly AuthService $auth,
        private readonly CookieAuth $cookieAuth,
        private readonly WebSession $webSession,
        private readonly ClubProspectRepository $prospects,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    /** GET /c/{code} — Mitglieder-Einladungs-Landing. */
    public function landing(Request $req): void
    {
        $raw = (string)($req->routeParams['code'] ?? '');
        if (preg_match('/^[a-z0-9]{1,16}$/i', $raw) !== 1) {
            Response::redirect('/');
        }
        $code = strtoupper($raw);
        $crew = $this->crews->profileByJoinCode($code);
        $this->view->render('crew/landing', [
            '_title'        => 'Vereins-Einladung · CYBERRIDE',
            'join_code'     => $code,
            'crew_name'     => $crew !== null ? (string)$crew['name'] : null,
            'crew_slug'     => $crew !== null ? (string)$crew['slug'] : null,
            'member_count'  => $crew !== null ? (int)$crew['member_count'] : null,
            'has_logo'      => $crew !== null && ($crew['logo_updated_at'] ?? null) !== null,
            'open_url'      => 'https://cyberride.world/c/' . rawurlencode($code),
            'app_store_url' => (string)$this->config->get('APP_STORE_URL', ''),
        ]);
    }

    /** GET /verein-aktivieren/{token} — Info-Landing + vorbelegtes Aktivierungs-Formular. */
    public function activate(Request $req): void
    {
        $token = (string)($req->routeParams['token'] ?? '');
        if (preg_match('/^[A-Za-z0-9]{8,64}$/', $token) !== 1) {
            Response::redirect('/');
        }
        $info = $this->crews->verifyInviteInfo($token);
        // Funnel: „Link geöffnet".
        $this->prospects->markLinkOpenedByToken($token, Clock::nowUtc()->format('Y-m-d H:i:s.v'));

        $loggedIn = $this->webSession->resolve() !== null;
        $this->view->render('crew/activate', [
            '_title'        => 'Verein aktivieren · CYBERRIDE',
            'token'         => $token,
            'info'          => $info,          // null = ungültig; ['used'=>true] = schon aktiviert
            'logged_in'     => $loggedIn,
            'app_store_url' => (string)$this->config->get('APP_STORE_URL', ''),
        ]);
    }

    /** POST /verein-aktivieren/{token} — Konto + Verein aktivieren, dann ins Cockpit. */
    public function activateSubmit(Request $req): void
    {
        $token = (string)($req->routeParams['token'] ?? '');
        if (preg_match('/^[A-Za-z0-9]{8,64}$/', $token) !== 1) {
            Response::redirect('/');
        }
        $info = $this->crews->verifyInviteInfo($token);
        if ($info === null || !empty($info['used'])) {
            $this->flash($info === null ? 'Dieser Aktivierungslink ist ungültig.' : 'Dieser Verein wurde bereits aktiviert.');
            Response::redirect('/verein-aktivieren/' . rawurlencode($token));
        }

        // Nutzer bestimmen: eingeloggt → nehmen; sonst Konto anlegen/einloggen.
        $ctx = $this->webSession->resolve();
        if ($ctx !== null) {
            $userId = (int)$ctx['user_id'];
        } else {
            $email = (string)($info['contact_email'] ?? '');
            $pw    = (string)$req->input('password', '');
            if ($email === '') {
                $this->flash('Für diese Einladung ist keine E-Mail hinterlegt — bitte melde dich zuerst an.');
                Response::redirect('/verein-aktivieren/' . rawurlencode($token));
            }
            if (strlen($pw) < 10) {
                $this->flash('Bitte ein Passwort mit mindestens 10 Zeichen wählen.');
                Response::redirect('/verein-aktivieren/' . rawurlencode($token));
            }
            // Existiert schon ein Konto zu dieser E-Mail? Dann nicht neu anlegen.
            if ($this->auth->userIdByEmail($email) !== null) {
                $this->flash('Zu dieser E-Mail gibt es bereits ein Konto. Bitte melde dich an und öffne den Link erneut.');
                Response::redirect('/login');
            }
            $this->auth->registerVerifiedForClub($email, $pw, (string)($info['org_name'] ?? $info['display_name'] ?? ''));
            $result = $this->auth->login($email, $pw, 'web', $req->userAgent, $req->ipBinary());
            Csrf::rotateForAuthState();
            $this->cookieAuth->setFromTokens($result['tokens']);
            $this->webSession->establish((int)$result['tokens']['user_id'], (int)$result['tokens']['session_id']);
            $userId = (int)$result['tokens']['user_id'];
        }

        try {
            $payload = $this->crews->activateVerifiedCrew($userId, $token);
        } catch (\Throwable $e) {
            $this->flash('Aktivierung fehlgeschlagen: ' . $e->getMessage());
            Response::redirect('/verein-aktivieren/' . rawurlencode($token));
        }
        $this->prospects->markActivatedByToken($token, (int)$payload['id'], $userId, Clock::nowUtc()->format('Y-m-d H:i:s.v'));

        $this->flash('Euer Verein ist aktiviert!');
        Response::redirect('/verein');
    }

    /** GET /verein — Captain-Cockpit (Mitglieder, Invite-Link, nächste Schritte). */
    public function cockpit(Request $req): void
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/login');
        }
        $crew = $this->crews->me((int)$ctx['user_id']);
        $this->view->render('crew/cockpit', [
            '_title'        => 'Mein Verein · CYBERRIDE',
            'crew'          => $crew,   // null = (noch) kein Verein
            'flash'         => $this->takeFlash(),
            'app_store_url' => (string)$this->config->get('APP_STORE_URL', ''),
        ]);
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
