<?php
declare(strict_types=1);

namespace App\Game\Admin;

use App\Support\Clock;
use PDO;

/**
 * Fahrten-/Routen-Verwaltung fürs Backoffice (GameAdmin_Concept.md Modul C):
 * Liste (Suche/Filter), 360°-Detail (Stats, Ingest-Status, Kanten/Pässe) und
 * Aktionen (für Spiel invalidieren, verbergen/löschen). Der Re-Ingest selbst
 * läuft über die async Ingest-Queue ({@see \App\Game\IngestJobRepository}).
 */
final class RideAdminService
{
    public const SORTS = ['created_at', 'distance_m'];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function search(
        string $q,
        string $source,
        string $sort,
        string $dir,
        int $limit,
        int $offset,
        ?int $userId = null,
    ): array {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'created_at';
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $where = ['r.deleted_at IS NULL'];
        $params = [];
        // Exakter Fahrer-Filter (z. B. „Fahrten dieses Users") — getrennt vom
        // unscharfen Freitext-q, damit nicht fremde Fahrten mit passendem Titel/
        // Mail-Teilstring auftauchen.
        if ($userId !== null) {
            $where[] = 'r.user_id = ?';
            $params[] = $userId;
        }
        if ($q !== '') {
            $where[] = '(r.title LIKE ? OR u.email LIKE ? OR u.public_handle LIKE ? OR r.public_id = ? OR u.id = ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $q;
            $params[] = ctype_digit($q) ? (int)$q : 0;
        }
        if ($source !== '' && in_array($source, ['app', 'import', 'strava', 'manual'], true)) {
            $where[] = 'r.source = ?';
            $params[] = $source;
        }
        $sql = 'SELECT r.id, r.public_id, r.title, r.source, r.distance_m, r.point_count,
                       r.created_at, u.id AS user_id, u.email AS user_email, u.public_handle AS handle,
                       EXISTS(SELECT 1 FROM game_edge_pass gp
                               WHERE gp.route_id = r.id AND gp.user_id = r.user_id
                                 AND gp.invalidated_at IS NULL) AS in_game
                  FROM routes r JOIN users u ON u.id = r.user_id
                 WHERE ' . implode(' AND ', $where) . "
                 ORDER BY r.{$sort} {$dir}
                 LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue($i, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function detail(int $routeId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.public_id, r.title, r.source, r.distance_m, r.point_count,
                    r.created_at, r.deleted_at, r.user_id,
                    u.email AS user_email, u.public_handle AS handle
               FROM routes r JOIN users u ON u.id = r.user_id
              WHERE r.id = ?'
        );
        $stmt->execute([$routeId]);
        $route = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($route === false) {
            return null;
        }

        $edges = (int)$this->scalar(
            'SELECT COUNT(DISTINCT edge_id) FROM game_edge_pass
              WHERE route_id = ? AND user_id = ? AND invalidated_at IS NULL',
            [$routeId, (int)$route['user_id']],
        );
        $passesActive = (int)$this->scalar(
            'SELECT COUNT(*) FROM game_edge_pass WHERE route_id = ? AND invalidated_at IS NULL',
            [$routeId],
        );
        $passesInvalid = (int)$this->scalar(
            'SELECT COUNT(*) FROM game_edge_pass WHERE route_id = ? AND invalidated_at IS NOT NULL',
            [$routeId],
        );

        // Letzter Ingest-Job (async Queue) für diese Route.
        $job = null;
        try {
            $js = $this->pdo->prepare('SELECT status, error_code, error_message, finished_at, summary_json FROM game_ingest_jobs WHERE route_id = ?');
            $js->execute([$routeId]);
            $row = $js->fetch(PDO::FETCH_ASSOC);
            $job = $row === false ? null : $row;
        } catch (\PDOException) {
            $job = null;
        }

        return [
            'route'          => $route,
            'game_edges'     => $edges,
            'passes_active'  => $passesActive,
            'passes_invalid' => $passesInvalid,
            'in_game'        => $edges > 0,
            'job'            => $job,
        ];
    }

    /** @return array{user_id:int}|null minimaler Datensatz für Folge-Aktionen */
    public function owner(int $routeId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM routes WHERE id = ?');
        $stmt->execute([$routeId]);
        $r = $stmt->fetchColumn();
        return $r === false ? null : ['user_id' => (int)$r];
    }

    /** Entfernt die Route aus dem Spiel: invalidiert alle aktiven Pässe. */
    public function invalidateGame(int $routeId, int $adminUserId, string $reason): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE game_edge_pass
                SET invalidated_at = ?, invalidated_by = ?, invalid_reason = ?
              WHERE route_id = ? AND invalidated_at IS NULL'
        );
        $stmt->execute([Clock::nowUtcString(), $adminUserId, mb_substr($reason, 0, 120), $routeId]);
        return $stmt->rowCount();
    }

    /** Soft-Delete (verbergen); der Karenz-Cleanup räumt später endgültig auf. */
    public function softDelete(int $routeId): void
    {
        $this->pdo->prepare('UPDATE routes SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL')
            ->execute([Clock::nowUtcString(), $routeId]);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), '1146') && !str_contains($e->getMessage(), '1054')) {
                throw $e;
            }
            return 0;
        }
    }
}
