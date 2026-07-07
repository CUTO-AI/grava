<?php
declare(strict_types=1);

namespace App\Social;

use PDO;

/**
 * „Community-Meilenstein" (Konzept §4/E4): die gemeinsam gefahrene Gesamt-
 * distanz überschreitet eine Schwelle (z. B. 1 Mio km). Öffentliches Aggregat.
 * Kein neues Schema nötig — die Queue dedupliziert je Schwelle (dedupe_key),
 * daher wird jeder Meilenstein genau einmal gepostet.
 */
final class CommunityMilestoneCollector implements PostSource
{
    /** Aufsteigende Schwellen in Kilometern. */
    private const THRESHOLDS_KM = [
        100_000, 250_000, 500_000,
        1_000_000, 2_000_000, 5_000_000,
        10_000_000, 25_000_000, 50_000_000, 100_000_000,
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PostCopy $copy,
        private readonly string $lang,
        private readonly string $channel,
    ) {}

    public function collect(string $date): array
    {
        try {
            $totalKm = ((float)$this->pdo->query(
                "SELECT COALESCE(SUM(distance_m), 0) FROM routes WHERE source = 'app' AND deleted_at IS NULL"
            )->fetchColumn()) / 1000.0;
        } catch (\PDOException $e) {
            error_log('social community_milestone: ' . $e->getMessage());
            return [];
        }

        // Höchste bereits erreichte Schwelle bestimmen.
        $reached = 0;
        foreach (self::THRESHOLDS_KM as $t) {
            if ($totalKm >= $t) {
                $reached = $t;
            }
        }
        if ($reached === 0) {
            return [];
        }

        return [new PostCandidate(
            kind:        'community_milestone',
            dedupeKey:   "community_milestone:{$reached}:{$this->lang}:{$this->channel}",
            entityKey:   "milestone_km:{$reached}",
            score:       70,
            body:        $this->copy->communityMilestone($reached, $this->lang),
            payloadJson: json_encode([
                'threshold_km' => $reached,
                'total_km'     => round($totalKm, 0),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        )];
    }
}
