<?php
declare(strict_types=1);

namespace App\Social;

use PDO;

/**
 * „Landkreis erobert" (Konzept §4/A1): Landkreise (OSM level 6), deren Besitzer
 * heute gewechselt hat. Crew-Eroberungen sind öffentlich; Solo-Rider nur mit
 * `social_optin=1` (Konzept §8/E3) — sonst wird der Kandidat übersprungen.
 */
final class RegionTakenCollector implements PostSource
{
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
                "SELECT r.id AS region_id, r.name AS region_name, o.held_fraction,
                        c.type AS owner_type, cr.name AS crew_name,
                        u.public_handle AS rider_handle, COALESCE(u.social_optin, 0) AS optin
                   FROM game_region_ownership o
                   JOIN game_region r   ON r.id = o.region_id
                   JOIN game_claimant c ON c.id = o.owner_claimant_id
              LEFT JOIN game_crew cr    ON cr.claimant_id = o.owner_claimant_id
              LEFT JOIN users u         ON u.id = c.user_id
                  WHERE r.level = 6
                    AND o.owner_claimant_id IS NOT NULL
                    AND o.owner_since >= :d1
                    AND o.owner_since <  :d2 + INTERVAL 1 DAY
               ORDER BY o.held_fraction DESC"
            );
            $stmt->execute([':d1' => $date, ':d2' => $date]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            error_log('social region_taken: Query fehlgeschlagen: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $type = (string)$row['owner_type'];
            if ($type === 'group') {
                $ownerName = (string)($row['crew_name'] ?? '');
            } elseif ($type === 'rider') {
                // Personenbezug nur mit Opt-in (E3).
                if ((int)$row['optin'] !== 1 || ($row['rider_handle'] ?? '') === '') {
                    continue;
                }
                $ownerName = '@' . (string)$row['rider_handle'];
            } else {
                continue;
            }
            if ($ownerName === '') {
                continue;
            }

            $regionId = (int)$row['region_id'];
            $region   = (string)$row['region_name'];
            $fraction = (float)$row['held_fraction'];

            $out[] = new PostCandidate(
                kind:        'region_taken',
                dedupeKey:   "region_taken:{$regionId}:{$date}:{$this->lang}:{$this->channel}",
                entityKey:   "region:{$regionId}",
                score:       60 + (int)round(max(0.0, min(1.0, $fraction)) * 40),
                body:        $this->copy->regionTaken($region, $ownerName, $type, $fraction, $this->lang),
                payloadJson: json_encode([
                    'region_id'     => $regionId,
                    'region'        => $region,
                    'owner'         => $ownerName,
                    'owner_type'    => $type,
                    'held_fraction' => $fraction,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        }
        return $out;
    }
}
