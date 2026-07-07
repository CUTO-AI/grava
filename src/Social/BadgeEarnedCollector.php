<?php
declare(strict_types=1);

namespace App\Social;

use PDO;

/**
 * „Seltenes Abzeichen" (Konzept §4/D3): heute erstmals erreichte Platin-/Onyx-
 * Stufen (tier ≥ 3). Personenbezogen → nur mit `social_optin=1` (E3).
 */
final class BadgeEarnedCollector implements PostSource
{
    private const MIN_TIER = 3; // 3=Platin, 4=Onyx

    public function __construct(
        private readonly PDO $pdo,
        private readonly PostCopy $copy,
        private readonly string $lang,
        private readonly string $channel,
    ) {}

    public function collect(string $date): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT b.user_id, b.family, b.tier, u.public_handle
                   FROM game_player_badge b
                   JOIN users u ON u.id = b.user_id
                  WHERE b.tier >= :tier
                    AND u.social_optin = 1
                    AND u.public_handle IS NOT NULL AND u.public_handle <> ''
                    AND b.earned_at >= :d1
                    AND b.earned_at <  :d2 + INTERVAL 1 DAY
               ORDER BY b.tier DESC, b.id ASC"
            );
            $stmt->execute([':tier' => self::MIN_TIER, ':d1' => $date, ':d2' => $date]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            error_log('social badge_earned: Query fehlgeschlagen: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $userId = (int)$row['user_id'];
            $family = (string)$row['family'];
            $tier   = (int)$row['tier'];
            $handle = '@' . (string)$row['public_handle'];

            $out[] = new PostCandidate(
                kind:        'badge_earned',
                dedupeKey:   "badge_earned:{$userId}:{$family}:{$tier}:{$this->lang}:{$this->channel}",
                entityKey:   "badge:{$userId}:{$family}:{$tier}",
                score:       $tier >= 4 ? 90 : 80,
                body:        $this->copy->badgeEarned($handle, $family, $tier, $this->lang),
                payloadJson: json_encode([
                    'handle'       => $handle,
                    'family'       => $family,
                    'family_label' => $this->copy->badgeFamilyLabel($family, $this->lang),
                    'tier'         => $tier,
                    'tier_name'    => $this->copy->tierName($tier, $this->lang),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        }
        return $out;
    }
}
