<?php
declare(strict_types=1);
namespace App\Game\Admin;

/**
 * Rollen/Rechte-Matrix des Backoffice (GameAdmin_Concept.md, Phase 0). Rein +
 * testbar, keine DB. `super` darf alles; die übrigen Rollen haben eine explizite
 * Rechte-Liste. Neue Rechte hier ergänzen — Module fragen via {@see can()}.
 */
final class AdminPermissions
{
    public const ROLES = ['super', 'operator', 'support', 'analyst'];

    /** @var array<string,list<string>> Rolle => erlaubte Rechte (super = alles) */
    private const MATRIX = [
        'operator' => [
            'dashboard.view', 'analytics.view',
            'user.view', 'user.support', 'user.ban', 'user.edit',
            'ride.view', 'ride.reingest', 'ride.invalidate', 'ride.delete',
            'review.view', 'review.act',
            'config.view', 'config.write',
            'region.manage', 'crew.manage',
            'audit.view', 'cron.view', 'cron.run',
        ],
        'support' => [
            'dashboard.view', 'analytics.view',
            'user.view', 'user.support',
            'ride.view',
            'review.view',
            'audit.view', 'cron.view',
        ],
        'analyst' => [
            'dashboard.view', 'analytics.view',
            'user.view', 'ride.view', 'review.view',
            'audit.view', 'cron.view',
        ],
    ];

    public static function isRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    public static function can(string $role, string $permission): bool
    {
        if ($role === 'super') {
            return true;
        }
        return in_array($permission, self::MATRIX[$role] ?? [], true);
    }

    /** @return list<string> alle Rechte einer Rolle (für UI-Gating / Tests) */
    public static function permissions(string $role): array
    {
        if ($role === 'super') {
            $all = [];
            foreach (self::MATRIX as $perms) {
                foreach ($perms as $p) {
                    $all[$p] = true;
                }
            }
            return array_keys($all);
        }
        return self::MATRIX[$role] ?? [];
    }
}
