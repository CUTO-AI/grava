<?php
declare(strict_types=1);

namespace App\Game\Admin;

use App\Support\Clock;
use PDO;

/**
 * Auswertungen fürs Backoffice (GameAdmin_Concept.md Phase 2): Zeitreihen
 * (Signups/Fahrten pro Tag), Quellen-Aufteilung und wöchentliche Signup-Kohorten-
 * Retention. Reine Lese-Aggregation.
 */
final class AnalyticsAdminService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array{d:string,n:int}> Signups je Tag (letzte $days Tage). */
    public function signupsPerDay(int $days = 30): array
    {
        return $this->perDay('users', 'created_at', null, $days);
    }

    /** @return list<array{d:string,n:int}> Fahrten je Tag. */
    public function ridesPerDay(int $days = 30): array
    {
        return $this->perDay('routes', 'created_at', 'deleted_at IS NULL', $days);
    }

    /** @return array<string,int> Fahrten je Quelle (letzte $days Tage). */
    public function sourceBreakdown(int $days = 30): array
    {
        $since = Clock::nowUtc()->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT source, COUNT(*) AS n FROM routes
              WHERE deleted_at IS NULL AND created_at >= ?
              GROUP BY source ORDER BY n DESC'
        );
        $stmt->execute([$since]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['source']] = (int)$r['n'];
        }
        return $out;
    }

    /**
     * Wöchentliche Signup-Kohorten-Retention: je Kohorte (ISO-Woche des Signups)
     * der Anteil Nutzer mit ≥1 Fahrt in Woche N nach Signup. week_offset via
     * DATEDIFF/7 (aktivität in Tagen 7N..7N+6 nach Signup).
     *
     * @return array{cohorts:list<array{week:string,size:int,retained:array<int,int>}>,maxOffset:int}
     */
    public function retentionCohorts(int $weeks = 8, int $maxOffset = 4): array
    {
        $since = Clock::nowUtc()->modify("-{$weeks} weeks")->format('Y-m-d H:i:s');

        // Kohortengrößen.
        $sizeStmt = $this->pdo->prepare(
            'SELECT YEARWEEK(created_at, 3) AS cohort, MIN(DATE(created_at)) AS week_start, COUNT(*) AS size
               FROM users WHERE created_at >= ?
              GROUP BY cohort ORDER BY cohort'
        );
        $sizeStmt->execute([$since]);
        $cohorts = [];
        foreach ($sizeStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cohorts[(string)$r['cohort']] = [
                'week' => (string)$r['week_start'],
                'size' => (int)$r['size'],
                'retained' => [],
            ];
        }

        // Retention je (cohort, week_offset).
        $retStmt = $this->pdo->prepare(
            'SELECT YEARWEEK(u.created_at, 3) AS cohort,
                    FLOOR(DATEDIFF(r.created_at, u.created_at) / 7) AS week_offset,
                    COUNT(DISTINCT u.id) AS retained
               FROM users u
               JOIN routes r ON r.user_id = u.id AND r.deleted_at IS NULL
                            AND r.created_at >= u.created_at
              WHERE u.created_at >= ?
              GROUP BY cohort, week_offset
             HAVING week_offset BETWEEN 0 AND ?'
        );
        $retStmt->bindValue(1, $since);
        $retStmt->bindValue(2, $maxOffset, PDO::PARAM_INT);
        $retStmt->execute();
        foreach ($retStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $c = (string)$r['cohort'];
            if (isset($cohorts[$c])) {
                $cohorts[$c]['retained'][(int)$r['week_offset']] = (int)$r['retained'];
            }
        }

        return ['cohorts' => array_values($cohorts), 'maxOffset' => $maxOffset];
    }

    /** @return list<array{d:string,n:int}> */
    private function perDay(string $table, string $col, ?string $extraWhere, int $days): array
    {
        $since = Clock::nowUtc()->modify("-{$days} days")->format('Y-m-d');
        $where = "DATE({$col}) >= ?";
        if ($extraWhere !== null) {
            $where .= " AND {$extraWhere}";
        }
        $stmt = $this->pdo->prepare(
            "SELECT DATE({$col}) AS d, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY d ORDER BY d"
        );
        $stmt->execute([$since]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['d' => (string)$r['d'], 'n' => (int)$r['n']];
        }
        return $out;
    }
}
