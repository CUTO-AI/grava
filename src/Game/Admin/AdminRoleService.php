<?php
declare(strict_types=1);
namespace App\Game\Admin;

use PDO;

/**
 * Löst die effektive Admin-Rolle eines Users auf und verwaltet Rollenvergaben
 * (GameAdmin_Concept.md, Phase 0). `super` kommt aus `ADMIN_EMAILS` (via
 * {@see AdminGuard}) und steht damit unabhängig von der DB fest; alle anderen
 * Rollen liegen in `admin_roles`. Rechte-Entscheidungen über {@see AdminPermissions}.
 */
final class AdminRoleService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AdminGuard $guard,
    ) {}

    /** Effektive Rolle oder null (kein Admin). ADMIN_EMAILS gewinnt (= super). */
    public function roleFor(int $userId, string $email): ?string
    {
        if ($this->guard->isAdminEmail($email)) {
            return 'super';
        }
        $stmt = $this->pdo->prepare('SELECT role FROM admin_roles WHERE user_id = ?');
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();
        return $role === false ? null : (string)$role;
    }

    public function can(int $userId, string $email, string $permission): bool
    {
        $role = $this->roleFor($userId, $email);
        return $role !== null && AdminPermissions::can($role, $permission);
    }

    /** @return list<array{user_id:int,email:string,handle:?string,role:string}> */
    public function list(): array
    {
        $sql = 'SELECT r.user_id, u.email, u.public_handle AS handle, r.role
                  FROM admin_roles r JOIN users u ON u.id = r.user_id
                 ORDER BY r.role, u.email';
        $out = [];
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'user_id' => (int)$r['user_id'],
                'email'   => (string)$r['email'],
                'handle'  => $r['handle'] !== null ? (string)$r['handle'] : null,
                'role'    => (string)$r['role'],
            ];
        }
        return $out;
    }

    /**
     * Vergibt eine Rolle (operator/support/analyst). `super` wird NICHT über die
     * DB vergeben (kommt aus ADMIN_EMAILS) → Ablehnung, um Verwirrung zu vermeiden.
     */
    public function setRole(int $userId, string $role): bool
    {
        if ($role === 'super' || !AdminPermissions::isRole($role)) {
            return false;
        }
        $this->pdo->prepare(
            'INSERT INTO admin_roles (user_id, role) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role)'
        )->execute([$userId, $role]);
        return true;
    }

    public function removeRole(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM admin_roles WHERE user_id = ?')->execute([$userId]);
    }

    /** Findet einen User per E-Mail oder Handle (für die Rollen-Vergabe). */
    public function findUser(string $emailOrHandle): ?array
    {
        $q = trim($emailOrHandle);
        if ($q === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, email, public_handle AS handle FROM users
              WHERE email = ? OR public_handle = ? LIMIT 1'
        );
        $stmt->execute([$q, $q]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }
}
