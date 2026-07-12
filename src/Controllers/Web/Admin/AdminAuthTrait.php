<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Game\Admin\AdminPermissions;
use App\Http\Middleware\Csrf;
use App\Http\Response;

/**
 * Gemeinsame Auth-/Flash-Helfer der neuen RBAC-Backoffice-Controller
 * (GameAdmin_Concept.md, Phase 0). Setzt voraus, dass die nutzende Klasse
 * `$webSession` (WebSession), `$auth` (AuthService) und `$roles` (AdminRoleService)
 * als Properties hält.
 */
trait AdminAuthTrait
{
    /**
     * Erzwingt Login + das angegebene Recht (rollenbasiert). Kein Login → /login;
     * kein Recht → 404 (verrät die Existenz des Admin-Bereichs nicht).
     *
     * @return array{0:array<string,mixed>,1:int,2:string} [user, adminId, role]
     */
    private function requirePermission(string $permission): array
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/login');
        }
        $uid = (int)$ctx['user_id'];
        $user = $this->auth->loadUserPublic($uid);
        $role = $this->roles->roleFor($uid, (string)($user['email'] ?? ''));
        if ($role === null || !AdminPermissions::can($role, $permission)) {
            Response::error('not_found', 'Nicht gefunden.', 404);
        }
        return [$user, $uid, $role];
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
