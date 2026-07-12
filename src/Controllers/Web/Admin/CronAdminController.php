<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Cli\CronRegistry;
use App\Cli\CronRunRepository;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminGuard;
use App\Game\Admin\GameAuditService;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Support\Clock;
use Closure;

/**
 * Admin: Cron-/Job-Monitoring (`/admin/cron`). Zeigt je bekanntem Cron-Befehl
 * (siehe {@see CronRegistry}) den letzten Lauf, letzten Erfolg, Status, Dauer/p95,
 * 24h-Läufe/Fehler und eine Überfälligkeits-Markierung; Detailseite mit Historie
 * und ein „jetzt ausführen"-Button. Struktur analog {@see GameAdminController}.
 */
final class CronAdminController
{
    private readonly WebView $view;

    /** @param Closure():\App\Cli\Commands $makeCli baut eine CLI mit trigger=manual */
    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminGuard $guard,
        private readonly CronRunRepository $runs,
        private readonly GameAuditService $audit,
        private readonly Closure $makeCli,
        private readonly int $overdueFactor,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function dashboard(Request $req): void
    {
        [$user] = $this->requireAdmin();

        // Frische Sicht: hängende Läufe vor dem Lesen reifen lassen.
        $maxByCommand = [];
        foreach (CronRegistry::commands() as $cmd) {
            $maxByCommand[$cmd] = CronRegistry::meta($cmd)['max_runtime_s'] ?? 900;
        }
        $this->runs->sweepStuck($maxByCommand, 900);

        $latest    = $this->runs->latestPerCommand();
        $lastOk    = $this->runs->lastSuccessPerCommand();
        $agg       = $this->runs->aggregates24h();
        $now       = Clock::nowUtc();

        $rows = [];
        foreach (CronRegistry::commands() as $cmd) {
            $meta = CronRegistry::meta($cmd);
            $last = $latest[$cmd] ?? null;
            $ageS = $last !== null ? $this->ageSeconds($now, (string)$last['started_at']) : null;
            $overdue = $last === null
                || ($ageS !== null && $ageS > $meta['interval_s'] * $this->overdueFactor);
            $rows[] = [
                'command'      => $cmd,
                'label'        => $meta['label'],
                'interval_s'   => $meta['interval_s'],
                'last'         => $last,
                'last_ok'      => $lastOk[$cmd] ?? null,
                'age_s'        => $ageS,
                'overdue'      => $overdue,
                'never'        => $last === null,
                'status'       => $last['status'] ?? null,
                'p95_ms'       => $this->runs->p95Recent($cmd),
                'agg'          => $agg[$cmd] ?? ['runs' => 0, 'failures' => 0, 'avg_ms' => null, 'max_ms' => null],
            ];
        }
        // Sortierung: fehlgeschlagen zuerst, dann überfällig, dann Registry-Reihenfolge.
        usort($rows, static function (array $a, array $b): int {
            $rank = static fn(array $r): int => ($r['status'] === 'failed' ? 0 : ($r['overdue'] ? 1 : 2));
            return $rank($a) <=> $rank($b);
        });

        $this->view->render('admin/cron/index', [
            '_title' => 'Cron · Jobs', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'rows' => $rows,
        ]);
    }

    public function history(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $command = CronRegistry::canonical((string)($req->routeParams['command'] ?? ''));
        if (!CronRegistry::isKnown($command)) {
            $this->flash('Unbekannter Cron-Befehl.');
            Response::redirect('/admin/cron');
        }
        $perPage = 50;
        $page = max(1, (int)$req->input('page', 1));
        $offset = ($page - 1) * $perPage;
        $total = $this->runs->historyCount($command);

        $this->view->render('admin/cron/history', [
            '_title' => 'Cron · ' . $command, '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'command' => $command,
            'label' => CronRegistry::meta($command)['label'] ?? $command,
            'runs' => $this->runs->history($command, $perPage, $offset),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'hasMore' => ($offset + $perPage) < $total,
        ]);
    }

    /** POST /admin/cron/{command}/run — Befehl jetzt ausführen (trigger=manual). */
    public function runNow(Request $req): void
    {
        [, $adminId] = $this->requireAdmin();
        $command = CronRegistry::canonical((string)($req->routeParams['command'] ?? ''));
        if (!CronRegistry::isKnown($command)) {
            $this->flash('Unbekannter Cron-Befehl.');
            Response::redirect('/admin/cron');
        }

        // Lange Jobs dürfen weder am Request-Timeout scheitern noch bei einem
        // Client-Disconnect eine dauerhafte „running"-Zeile hinterlassen.
        @set_time_limit(0);
        ignore_user_abort(true);

        $cli = ($this->makeCli)();
        ob_start();
        $code = $cli->run(['admin', $command]);
        $out = (string)ob_get_clean();

        $this->audit->record($adminId, 'cron_run_manual', $command, ['exit' => $code]);
        $tail = trim(substr($out, -400));
        $this->flash("„{$command}" . '" ausgeführt (exit ' . $code . ')' . ($tail !== '' ? ": {$tail}" : ''));
        Response::redirect('/admin/cron/' . $command);
    }

    private function ageSeconds(\DateTimeImmutable $now, string $startedAt): ?int
    {
        try {
            $t = new \DateTimeImmutable($startedAt, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
        return max(0, $now->getTimestamp() - $t->getTimestamp());
    }

    /** @return array{0:array<string,mixed>,1:int} [user, adminId] */
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
