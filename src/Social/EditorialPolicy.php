<?php
declare(strict_types=1);

namespace App\Social;

use PDO;

/**
 * Redaktions-Regeln (Konzept §5): entscheidet, welche eingesammelten Kandidaten
 * überhaupt gesendet werden dürfen.
 *
 * - **Expiry:** ein pending-Kandidat, der bis zum nächsten Sende-Lauf zu alt ist
 *   (> maxAgeHours), verfällt (→ `skipped`), statt veraltete News zu posten.
 * - **Cooldown:** dasselbe Objekt (entity_key, z. B. eine Region) wird nicht
 *   binnen `cooldownDays` erneut gepostet (Konzept §4/A1 „max 1/Region/Tag").
 *
 * Die eigentliche Auswahl unter dem Tages-Limit bleibt score-basiert im
 * {@see SocialService}; diese Klasse liefert nur die Filter.
 */
final class EditorialPolicy
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxAgeHours,
        private readonly int $cooldownDays,
    ) {}

    /** Markiert überfällige pending-Kandidaten als skipped. Gibt die Anzahl zurück. */
    public function pruneStale(): int
    {
        $hours = max(1, $this->maxAgeHours);
        $stmt = $this->pdo->prepare(
            "UPDATE social_post_queue
                SET status = 'skipped', error = 'expired'
              WHERE status = 'pending'
                AND created_at < UTC_TIMESTAMP(3) - INTERVAL {$hours} HOUR"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Wurde dasselbe Objekt kürzlich schon gepostet? Greift nur bei echt
     * gesendeten (published) Einträgen; datums-eindeutige Entities
     * (day:…, faction:…) sind praktisch nie betroffen.
     */
    public function entityOnCooldown(string $kind, string $entityKey): bool
    {
        if ($entityKey === '' || $this->cooldownDays <= 0) {
            return false;
        }
        $days = (int)$this->cooldownDays;
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM social_post_queue
              WHERE kind = :kind AND entity_key = :ek AND status = 'published'
                AND published_at >= UTC_TIMESTAMP(3) - INTERVAL {$days} DAY
              LIMIT 1"
        );
        $stmt->execute([':kind' => $kind, ':ek' => $entityKey]);
        return $stmt->fetchColumn() !== false;
    }
}
