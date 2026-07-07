<?php
declare(strict_types=1);

namespace App\Social;

use PDO;

/**
 * Aggregiert die Tagesaktivität aus den bestehenden Spiel-/Routen-Tabellen zu
 * einem {@see DailyReport} (Konzept §3/§4). Jede Kennzahl ist einzeln
 * fehlertolerant: fehlt eine Tabelle/Spalte (z. B. Gebiete noch nicht importiert),
 * bleibt die Kennzahl 0 statt den ganzen Bericht zu sprengen.
 */
final class DailyReportCollector
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param string $date 'YYYY-MM-DD' (UTC) */
    public function collect(string $date): DailyReport
    {
        $rides = $this->ridesToday($date);
        $rush  = $this->rushOfDay($date);
        return new DailyReport(
            date:            $date,
            rides:           $rides['count'],
            distanceKm:      $rides['km'],
            edgesTakenOver:  $this->edgesTakenOver($date),
            countiesChanged: $this->countiesChanged($date),
            rushCrewName:    $rush['crew'],
            rushEdges:       $rush['edges'],
        );
    }

    /**
     * Heutige App-Fahrten + Distanz. Nur `source='app'` (echte Aufzeichnungen,
     * keine Importe/manuellen Routen), nicht soft-deleted.
     *
     * @return array{count:int, km:float}
     */
    private function ridesToday(string $date): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS c, COALESCE(SUM(distance_m), 0) AS m
                   FROM routes
                  WHERE source = 'app'
                    AND deleted_at IS NULL
                    AND created_at >= :d1
                    AND created_at <  :d2 + INTERVAL 1 DAY"
            );
            $stmt->execute([':d1' => $date, ':d2' => $date]);
            $row = $stmt->fetch() ?: ['c' => 0, 'm' => 0];
            return [
                'count' => (int)$row['c'],
                'km'    => round(((float)$row['m']) / 1000.0, 1),
            ];
        } catch (\PDOException $e) {
            $this->logMetricError('rides', $e);
            return ['count' => 0, 'km' => 0.0];
        }
    }

    /**
     * Kanten, die heute den Besitzer gewechselt haben (Erstbefahrung +
     * Übernahme) — abgeleitet aus game_edge.owner_since.
     */
    private function edgesTakenOver(string $date): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM game_edge
                  WHERE owner_since >= :d1
                    AND owner_since <  :d2 + INTERVAL 1 DAY"
            );
            $stmt->execute([':d1' => $date, ':d2' => $date]);
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            $this->logMetricError('edges_taken_over', $e);
            return 0;
        }
    }

    /** Landkreise (OSM level 6) mit Führungs-/Besitzwechsel heute. */
    private function countiesChanged(string $date): int
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*)
                   FROM game_region_ownership o
                   JOIN game_region r ON r.id = o.region_id
                  WHERE r.level = 6
                    AND o.owner_since >= :d1
                    AND o.owner_since <  :d2 + INTERVAL 1 DAY"
            );
            $stmt->execute([':d1' => $date, ':d2' => $date]);
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            $this->logMetricError('counties_changed', $e);
            return 0;
        }
    }

    /**
     * Stärkster heute abgeschlossener Rush: Crew-Name + Anzahl der in seinem
     * Zeitfenster erst-eroberten Kanten (game_edge_pass.rush_id → distinct edge).
     *
     * @return array{crew:?string, edges:int}
     */
    private function rushOfDay(string $date): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT c.name AS crew,
                        COUNT(DISTINCT p.edge_id) AS edges
                   FROM game_rush ru
                   JOIN game_crew c ON c.id = ru.crew_id
              LEFT JOIN game_edge_pass p ON p.rush_id = ru.id
                  WHERE ru.status = 'completed'
                    AND ru.end_at >= :d1
                    AND ru.end_at <  :d2 + INTERVAL 1 DAY
               GROUP BY ru.id, c.name
               ORDER BY edges DESC
                  LIMIT 1"
            );
            $stmt->execute([':d1' => $date, ':d2' => $date]);
            $row = $stmt->fetch();
            if ($row === false) {
                return ['crew' => null, 'edges' => 0];
            }
            return ['crew' => (string)$row['crew'], 'edges' => (int)$row['edges']];
        } catch (\PDOException $e) {
            $this->logMetricError('rush_of_day', $e);
            return ['crew' => null, 'edges' => 0];
        }
    }

    /**
     * Loggt einen Query-Fehler, degradiert aber die Kennzahl auf 0 statt den
     * ganzen Bericht zu sprengen. Fehlt z. B. eine Tabelle (Gebiete noch nicht
     * importiert), ist das erwartbar; ein echter Bug wird so wenigstens sichtbar.
     */
    private function logMetricError(string $metric, \PDOException $e): void
    {
        error_log("social daily_report: Kennzahl '{$metric}' fehlgeschlagen: " . $e->getMessage());
    }
}
