<?php
declare(strict_types=1);

namespace App\Social;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * „Wochenrückblick" (Konzept §4/E2): Aggregat der letzten 7 Tage. Liefert nur
 * SONNTAGS (UTC) einen Kandidaten, damit die Kadenz wöchentlich bleibt.
 * Öffentliches Aggregat — kein Opt-in nötig. Jede Kennzahl fehlertolerant.
 */
final class WeeklyRecapCollector implements PostSource
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
        $isoWeek = $day->format('o-\WW');
        $start   = $day->modify('-6 days')->format('Y-m-d'); // 7-Tage-Fenster inkl. heute

        $rides    = $this->ridesInRange($start, $date);
        $edges    = $this->countInRange('game_edge', 'owner_since', $start, $date);
        $counties = $this->countiesInRange($start, $date);

        // Kein einziger Datenpunkt → nicht posten.
        if ($rides['count'] === 0 && $edges === 0 && $counties === 0) {
            return [];
        }

        $payload = [
            'iso_week'         => $isoWeek,
            'rides'            => $rides['count'],
            'distance_km'      => $rides['km'],
            'edges_taken_over' => $edges,
            'counties_changed' => $counties,
        ];

        return [new PostCandidate(
            kind:        'weekly_recap',
            dedupeKey:   "weekly_recap:{$isoWeek}:{$this->lang}:{$this->channel}",
            entityKey:   "week:{$isoWeek}",
            score:       48,
            body:        $this->copy->weeklyRecap($payload, $this->lang),
            payloadJson: json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        )];
    }

    /** @return array{count:int, km:float} */
    private function ridesInRange(string $start, string $end): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS c, COALESCE(SUM(distance_m), 0) AS m
                   FROM routes
                  WHERE source = 'app' AND deleted_at IS NULL
                    AND created_at >= :s AND created_at < :e + INTERVAL 1 DAY"
            );
            $stmt->execute([':s' => $start, ':e' => $end]);
            $row = $stmt->fetch() ?: ['c' => 0, 'm' => 0];
            return ['count' => (int)$row['c'], 'km' => round(((float)$row['m']) / 1000.0, 1)];
        } catch (\PDOException $e) {
            error_log('social weekly_recap rides: ' . $e->getMessage());
            return ['count' => 0, 'km' => 0.0];
        }
    }

    private function countInRange(string $table, string $col, string $start, string $end): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM {$table}
                  WHERE {$col} >= :s AND {$col} < :e + INTERVAL 1 DAY"
            );
            $stmt->execute([':s' => $start, ':e' => $end]);
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log("social weekly_recap {$table}: " . $e->getMessage());
            return 0;
        }
    }

    private function countiesInRange(string $start, string $end): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*)
                   FROM game_region_ownership o
                   JOIN game_region r ON r.id = o.region_id
                  WHERE r.level = 6
                    AND o.owner_since >= :s AND o.owner_since < :e + INTERVAL 1 DAY"
            );
            $stmt->execute([':s' => $start, ':e' => $end]);
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log('social weekly_recap counties: ' . $e->getMessage());
            return 0;
        }
    }
}
