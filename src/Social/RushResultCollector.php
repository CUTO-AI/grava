<?php
declare(strict_types=1);

namespace App\Social;

use PDO;

/**
 * „Rush-Ergebnis" (Konzept §4/B2): heute abgeschlossene Crew-Rushes mit der
 * Anzahl der in ihrem Zeitfenster eroberten Kanten. Crew-basiert → öffentlich,
 * kein Opt-in nötig.
 */
final class RushResultCollector implements PostSource
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
                "SELECT ru.id AS rush_id, ru.multiplier, c.name AS crew_name,
                        COUNT(DISTINCT p.edge_id)  AS edges,
                        COUNT(DISTINCT p.user_id)  AS riders
                   FROM game_rush ru
                   JOIN game_crew c ON c.id = ru.crew_id
              LEFT JOIN game_edge_pass p ON p.rush_id = ru.id
                  WHERE ru.status = 'completed'
                    AND ru.end_at >= :d1
                    AND ru.end_at <  :d2 + INTERVAL 1 DAY
               GROUP BY ru.id, ru.multiplier, c.name
               ORDER BY edges DESC"
            );
            $stmt->execute([':d1' => $date, ':d2' => $date]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            error_log('social rush_result: Query fehlgeschlagen: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $crew = (string)($row['crew_name'] ?? '');
            if ($crew === '') {
                continue;
            }
            $rushId = (int)$row['rush_id'];
            $edges  = (int)$row['edges'];
            $riders = (int)$row['riders'];
            $mult   = (float)$row['multiplier'];

            $out[] = new PostCandidate(
                kind:        'rush_result',
                dedupeKey:   "rush_result:{$rushId}:{$this->lang}",
                entityKey:   "rush:{$rushId}",
                score:       55 + min(40, $edges),
                body:        $this->copy->rushResult($crew, $edges, $riders, $mult, $this->lang),
                payloadJson: json_encode([
                    'rush_id'    => $rushId,
                    'crew'       => $crew,
                    'edges'      => $edges,
                    'riders'     => $riders,
                    'multiplier' => $mult,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        }
        return $out;
    }
}
