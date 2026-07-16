<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Database\Db;
use App\Game\Admin\AdminGuard;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use PDO;
use Throwable;

/**
 * Admin: DB-Performance (`/admin/db-perf`). Liest read-only aus MySQL
 * `performance_schema` die teuersten normalisierten Queries (Summe/Ø/Max-Laufzeit,
 * geprüfte Zeilen, Full-Scans) sowie die aktuell laufenden Threads
 * (`information_schema.PROCESSLIST`) — um langsame Prod-Abfragen ohne SSH zu finden.
 * Jede Abfrage ist einzeln fehlertolerant: fehlen dem DB-Benutzer die Rechte
 * (performance_schema / PROCESS), zeigt die Seite das + die nötigen Grants, statt
 * zu brechen. Struktur analog {@see CronAdminController}.
 */
final class DbPerfAdminController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminGuard $guard,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function dashboard(Request $req): void
    {
        $user = $this->requireAdmin();
        $pdo  = Db::pdo();

        // Teuerste normalisierte Queries seit letztem Reset/Server-Start.
        [$digests, $digestErr] = $this->safe(static fn() => $pdo->query(
            "SELECT SCHEMA_NAME, DIGEST_TEXT, COUNT_STAR AS calls,
                    ROUND(SUM_TIMER_WAIT/1e12, 3) AS total_s,
                    ROUND(AVG_TIMER_WAIT/1e9, 1)  AS avg_ms,
                    ROUND(MAX_TIMER_WAIT/1e9, 1)  AS max_ms,
                    SUM_ROWS_EXAMINED           AS rows_examined,
                    SUM_ROWS_SENT               AS rows_sent,
                    SUM_NO_INDEX_USED           AS full_scans,
                    SUM_CREATED_TMP_DISK_TABLES AS tmp_disk
             FROM performance_schema.events_statements_summary_by_digest
             WHERE DIGEST_TEXT IS NOT NULL
             ORDER BY SUM_TIMER_WAIT DESC
             LIMIT 30"
        )->fetchAll(PDO::FETCH_ASSOC));

        // Was gerade arbeitet (Hang-Diagnose).
        [$proc, $procErr] = $this->safe(static fn() => $pdo->query(
            "SELECT ID, USER, DB, COMMAND, TIME, STATE, LEFT(INFO, 500) AS INFO
             FROM information_schema.PROCESSLIST
             WHERE COMMAND <> 'Sleep' AND INFO IS NOT NULL
             ORDER BY TIME DESC
             LIMIT 30"
        )->fetchAll(PDO::FETCH_ASSOC));

        [$grants] = $this->safe(static fn() => $pdo->query(
            'SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN));

        $this->view->render('admin/db-perf/index', [
            '_title' => 'DB-Performance', '_authedUser' => $user, '_layoutWide' => true,
            'flash'     => $this->takeFlash(),
            'digests'   => $digests ?? [], 'digestErr' => $digestErr,
            'proc'      => $proc ?? [],    'procErr'   => $procErr,
            'grants'    => $grants ?? [],
        ]);
    }

    /** POST /admin/db-perf/reset — Digest-Statistik nullen (frisches Messfenster). */
    public function reset(Request $req): void
    {
        $this->requireAdmin();
        [, $err] = $this->safe(static fn() => Db::pdo()->exec(
            'TRUNCATE performance_schema.events_statements_summary_by_digest'));
        $this->flash($err === null
            ? 'Statistik zurückgesetzt – das Messfenster beginnt jetzt.'
            : ('Zurücksetzen fehlgeschlagen: ' . $err));
        Response::redirect('/admin/db-perf');
    }

    /**
     * Führt `$fn` aus und fängt Fehler ab, damit eine fehlende DB-Berechtigung
     * die Seite nicht bricht.
     *
     * @return array{0:mixed,1:?string} [Ergebnis, Fehlermeldung]
     */
    private function safe(callable $fn): array
    {
        try {
            return [$fn(), null];
        } catch (Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    /** @return array<string,mixed> eingeloggter Admin-User */
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
        return $user;
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
