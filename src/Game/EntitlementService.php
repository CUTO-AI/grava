<?php
declare(strict_types=1);

namespace App\Game;

use PDO;

/**
 * Pro-Berechtigung (RouteSuggestion_Concept.md §7, Phase D). Zentrale Stelle, die
 * entscheidet, ob ein Nutzer ein Pro-Feature verwenden darf.
 *
 * WICHTIG: Solange das Flag `pro_gating_enabled` = 0 ist (Default, Beta), gibt
 * {@see allowsPro()} für ALLE `true` zurück — es ist also alles offen. Erst wenn
 * das Flag im Admin auf 1 gesetzt wird, greift die tatsächliche Prüfung gegen
 * `users.pro_until` (die der spätere Kauf-/Abo-Flow setzt).
 */
final class EntitlementService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly GameConfig $config,
    ) {}

    /** Ist das Pro-Gate scharfgeschaltet? (Admin-justierbar, Default 0 = aus.) */
    public function gatingEnabled(): bool
    {
        return $this->config->bool('pro_gating_enabled');
    }

    /** Hat der Nutzer eine gültige (nicht abgelaufene) Pro-Berechtigung? */
    public function isPro(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT pro_until FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $until = $stmt->fetchColumn();
        if ($until === false || $until === null) {
            return false;
        }
        return strtotime((string)$until) > time();
    }

    /**
     * Darf der Nutzer Pro-Features nutzen? Gate aus → immer true (Beta: alles
     * offen). Gate an → nur mit gültiger Pro-Berechtigung.
     */
    public function allowsPro(int $userId): bool
    {
        return !$this->gatingEnabled() || $this->isPro($userId);
    }
}
