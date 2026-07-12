<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminRoleService;
use App\Game\Admin\BroadcastService;
use App\Game\Admin\GameAuditService;
use App\Http\Request;
use App\Http\Response;

/**
 * Broadcast-Push (`/admin/broadcast`, GameAdmin_Concept.md Phase 2): Mitteilung
 * verfassen + Segment wählen + einreihen. Versand macht der Cron-Worker
 * (game:broadcast-run) entkoppelt. Recht `broadcast.send` (operator/super).
 */
final class BroadcastAdminController
{
    use AdminAuthTrait;

    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminRoleService $roles,
        private readonly BroadcastService $broadcasts,
        private readonly GameAuditService $audit,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        [$user] = $this->requirePermission('broadcast.send');
        $estimates = [];
        foreach (BroadcastService::SEGMENTS as $s) {
            $estimates[$s] = $this->broadcasts->estimate($s);
        }
        $this->view->render('admin/broadcast/index', [
            '_title' => 'Admin · Broadcast', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'segments' => BroadcastService::SEGMENTS,
            'estimates' => $estimates,
            'recent' => $this->broadcasts->list(30),
        ]);
    }

    public function create(Request $req): void
    {
        [, $adminId] = $this->requirePermission('broadcast.send');
        $title = trim((string)$req->input('title', ''));
        $body = trim((string)$req->input('body', ''));
        $deeplink = trim((string)$req->input('deeplink', ''));
        $segment = trim((string)$req->input('segment', 'all'));

        if ($title === '' || $body === '') {
            $this->flash('Titel und Text sind erforderlich.');
            Response::redirect('/admin/broadcast');
        }
        $id = $this->broadcasts->queue($adminId, $title, $body, $deeplink, $segment);
        $this->audit->record($adminId, 'broadcast_queue', 'broadcast:' . $id, ['segment' => $segment, 'title' => $title]);
        $this->flash("Broadcast #{$id} eingereiht — Versand läuft im Hintergrund (Segment {$segment}).");
        Response::redirect('/admin/broadcast');
    }
}
