<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminRoleService;
use App\Game\Admin\AnalyticsAdminService;
use App\Game\Admin\RideAdminService;
use App\Game\Admin\UserAdminService;
use App\Http\Request;
use App\Http\Response;

/**
 * Auswertungen + Exporte (`/admin/analytics`, GameAdmin_Concept.md Phase 2):
 * Zeitreihen, Quellen, Retention-Kohorten sowie CSV-Exporte (User/Fahrten).
 * Read-only, Recht `analytics.view`.
 */
final class AnalyticsAdminController
{
    use AdminAuthTrait;

    private const CSV_CAP = 5000;

    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminRoleService $roles,
        private readonly AnalyticsAdminService $analytics,
        private readonly UserAdminService $users,
        private readonly RideAdminService $rides,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        [$user, , $role] = $this->requirePermission('analytics.view');
        $this->view->render('admin/analytics/index', [
            '_title' => 'Admin · Analytics', '_authedUser' => $user, '_layoutWide' => true,
            'role' => $role,
            'signups' => $this->analytics->signupsPerDay(30),
            'rides' => $this->analytics->ridesPerDay(30),
            'sources' => $this->analytics->sourceBreakdown(30),
            'retention' => $this->analytics->retentionCohorts(8, 4),
        ]);
    }

    public function usersCsv(Request $req): void
    {
        $this->requirePermission('analytics.view');
        $rows = $this->users->search('', 'created_at', 'desc', self::CSV_CAP, 0);
        $this->stream('users.csv',
            ['id', 'email', 'handle', 'display_name', 'status', 'banned', 'email_verified_at', 'created_at'],
            $rows,
            static fn(array $r): array => [
                $r['id'], $r['email'], $r['handle'] ?? '', $r['display_name'] ?? '',
                $r['status'], (int)$r['banned'], $r['email_verified_at'] ?? '', $r['created_at'],
            ],
        );
    }

    public function ridesCsv(Request $req): void
    {
        $this->requirePermission('analytics.view');
        $rows = $this->rides->search('', '', 'created_at', 'desc', self::CSV_CAP, 0);
        $this->stream('rides.csv',
            ['id', 'user_id', 'user_email', 'source', 'distance_m', 'point_count', 'in_game', 'created_at'],
            $rows,
            static fn(array $r): array => [
                $r['id'], $r['user_id'], $r['user_email'], $r['source'],
                $r['distance_m'] ?? '', $r['point_count'] ?? '', (int)$r['in_game'], $r['created_at'],
            ],
        );
    }

    /**
     * @param list<string> $header
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):list<mixed> $map
     */
    private function stream(string $filename, array $header, array $rows, callable $map): void
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $header, ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, $map($r), ',', '"', '');
        }
        rewind($fh);
        $csv = (string)stream_get_contents($fh);
        fclose($fh);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        Response::text($csv, 200, 'text/csv');
    }
}
