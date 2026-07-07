<?php
declare(strict_types=1);

namespace App\Social;

use PDO;

/**
 * „Neuer KOM / Bestzeit" (Konzept §4/D1): heute geschlagene Segment-Rekorde
 * (game_event type record_beaten). Der neue Rekordhalter ist `actor_user_id`.
 * Personenbezogen → nur mit `social_optin=1` (E3). Segment wird — sofern
 * vorhanden — über den Gebietsnamen der Kante benannt.
 */
final class RecordBeatenCollector implements PostSource
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PostCopy $copy,
        private readonly string $lang,
    ) {}

    public function collect(string $date): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT ev.edge_id, ev.actor_user_id, ev.payload,
                        u.public_handle, reg.name AS region_name
                   FROM game_event ev
                   JOIN users u        ON u.id = ev.actor_user_id
              LEFT JOIN game_edge ge   ON ge.id = ev.edge_id
              LEFT JOIN game_region reg ON reg.id = ge.region_id
                  WHERE ev.type = 'record_beaten'
                    AND u.social_optin = 1
                    AND u.public_handle IS NOT NULL AND u.public_handle <> ''
                    AND ev.created_at >= :d1
                    AND ev.created_at <  :d2 + INTERVAL 1 DAY
               ORDER BY ev.created_at DESC"
            );
            $stmt->execute([':d1' => $date, ':d2' => $date]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            error_log('social record_beaten: Query fehlgeschlagen: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $edgeId = (int)$row['edge_id'];
            $handle = '@' . (string)$row['public_handle'];
            $region = ($row['region_name'] ?? '') !== '' ? (string)$row['region_name'] : null;

            // Geschwindigkeit best-effort aus dem Event-Payload.
            $speed = null;
            if (($row['payload'] ?? null) !== null) {
                $p = json_decode((string)$row['payload'], true);
                if (is_array($p) && isset($p['avg_speed_kmh']) && is_numeric($p['avg_speed_kmh'])) {
                    $speed = (float)$p['avg_speed_kmh'];
                }
            }

            $out[] = new PostCandidate(
                kind:        'record_beaten',
                dedupeKey:   "record_beaten:{$edgeId}:" . (int)$row['actor_user_id'] . ":{$date}:{$this->lang}",
                entityKey:   "record:{$edgeId}",
                score:       65,
                body:        $this->copy->komRecord($handle, $region, $speed, $this->lang),
                payloadJson: json_encode([
                    'handle'        => $handle,
                    'region'        => $region,
                    'edge_id'       => $edgeId,
                    'avg_speed_kmh' => $speed,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        }
        return $out;
    }
}
