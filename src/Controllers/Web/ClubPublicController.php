<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Growth\ClubProspectRepository;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;

/**
 * Öffentliche Vereins-Seite (UserGrowth §15 / CrewInvite_Onboarding_Spec §8.3):
 *   - GET  /vereine            → Cyber-gestylte Landing „CYBERRIDE für Vereine"
 *   - POST /vereine/interesse  → Interesse-Lead → club_prospect (Funnel-Start)
 *
 * Kein Self-Service-Onboarding: Vereine werden per Admin/Token freigeschaltet.
 * Das Formular erzeugt lediglich einen Prospect, den das Team einlädt.
 */
final class ClubPublicController
{
    public function __construct(
        private readonly ClubProspectRepository $prospects,
        private readonly string $cyberPath, // …/public/cyber
    ) {}

    /** GET /vereine — rendert die eigenständige Cyber-Seite (EN/DE). */
    public function page(): never
    {
        Csrf::ensureStarted();
        $flash = $_SESSION['club_flash'] ?? null;
        $old   = $_SESSION['club_old'] ?? [];
        unset($_SESSION['club_flash'], $_SESSION['club_old']);

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');

        $CR_ASSETS = '/cyber/assets';
        $CR_CSRF   = Csrf::token();
        $CR_FLASH  = is_array($flash) ? $flash : null; // ['ok'=>bool,'key'=>string]
        $CR_OLD    = is_array($old) ? $old : [];
        require $this->cyberPath . '/vereine.php';
        exit;
    }

    /** POST /vereine/interesse — Lead validieren + als Prospect anlegen. */
    public function submitInterest(Request $req): void
    {
        // Honeypot: befülltes verstecktes Feld ⇒ stiller „Erfolg" (Bot abweisen).
        if (trim((string)$req->input('website', '')) !== '') {
            Response::redirect('/vereine');
        }

        $name       = trim((string)$req->input('club_name', ''));
        $email      = strtolower(trim((string)$req->input('contact_email', '')));
        $region     = trim((string)$req->input('region', '')) ?: null;
        $discipline = trim((string)$req->input('discipline', '')) ?: null;

        if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $_SESSION['club_old'] = [
                'club_name'     => $name,
                'region'        => (string)$region,
                'contact_email' => $email,
                'discipline'    => (string)$discipline,
            ];
            $this->flash(false, 'err');
            Response::redirect('/vereine');
        }

        // Bestehenden Prospect (Name+Region) NICHT im Funnel zurückwerfen:
        // Status/Notiz nur bei Neuanlage setzen, Kontaktdaten sonst aktualisieren.
        $key = ClubProspectRepository::dedupKey($name, $region);
        $data = [
            'name'          => $name,
            'landkreis'     => $region,
            'discipline'    => $discipline,
            'contact_email' => $email,
        ];
        if ($this->prospects->byDedupKey($key) === null) {
            $data['status'] = 'prospect';
            $data['notes']  = 'Öffentliches Interesse-Formular (cyberride.world/vereine)';
        }
        $this->prospects->upsert($data);

        $this->flash(true, 'ok');
        Response::redirect('/vereine');
    }

    private function flash(bool $ok, string $key): void
    {
        Csrf::ensureStarted();
        $_SESSION['club_flash'] = ['ok' => $ok, 'key' => $key];
    }
}
