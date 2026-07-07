<?php
declare(strict_types=1);

namespace App\Social;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * „Fraktions-Wochenstand" (Konzept §4/C2): aktueller Anteil gehaltenen Terrains
 * je Fraktion. Bewusst der Wochenstand statt der Lead-Wechsel-Meldung (C1) —
 * für C1 fehlt eine Fraktions-Snapshot-Historie. Liefert nur SONNTAGS (UTC)
 * einen Kandidaten, damit die Kadenz wöchentlich bleibt.
 */
final class FactionStandingCollector implements PostSource
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PostCopy $copy,
        private readonly string $lang,
        private readonly string $channel,
    ) {}

    public function collect(string $date): array
    {
        $day = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone('UTC'));
        if ($day === false || $day->format('N') !== '7') {
            return []; // nur sonntags
        }
        $isoWeek = $day->format('o-\WW'); // z. B. 2026-W27

        try {
            $stmt = $this->pdo->query(
                "SELECT f.name, f.key_slug,
                        COALESCE(SUM(e.length_m), 0) AS len,
                        COUNT(e.id) AS edges
                   FROM game_faction f
              LEFT JOIN game_crew cr ON cr.faction_id = f.id
              LEFT JOIN game_edge e  ON e.owner_claimant_id = cr.claimant_id
               GROUP BY f.id, f.name, f.key_slug
               ORDER BY len DESC"
            );
            $rows = $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            error_log('social faction_standing: Query fehlgeschlagen: ' . $e->getMessage());
            return [];
        }

        $total = array_sum(array_map(static fn($r) => (float)$r['len'], $rows));
        if (count($rows) < 2 || $total <= 0.0) {
            return []; // noch kein aussagekräftiger Stand
        }

        $factions = array_map(static function ($r) use ($total) {
            return [
                'name'   => (string)$r['name'],
                'key'    => (string)$r['key_slug'],
                'edges'  => (int)$r['edges'],
                'len_m'  => (float)$r['len'],
                'share'  => (int)round(((float)$r['len'] / $total) * 100),
            ];
        }, $rows);

        return [new PostCandidate(
            kind:        'faction_standing',
            dedupeKey:   "faction_standing:{$isoWeek}:{$this->lang}:{$this->channel}",
            entityKey:   "faction:{$isoWeek}",
            score:       45,
            body:        $this->copy->factionStanding($factions, $this->lang),
            payloadJson: json_encode(['iso_week' => $isoWeek, 'factions' => $factions], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        )];
    }
}
