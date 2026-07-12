<?php
declare(strict_types=1);
namespace App\Game\Admin;

use App\Game\GameConfig;
use PDO;

/** Heuristik-Review-Queue (markiert nur, invalidiert NICHT automatisch). */
final class GameModerationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly GameConfig $config,
    ) {}

    /** @return list<array{user_id:int,handle:?string,passes_that_day:int,ridden_on:string}> */
    public function highVolumeRiders(int $limit = 50): array
    {
        $threshold = $this->config->int('mod_max_passes_per_day');
        $stmt = $this->pdo->prepare(
            'SELECT p.user_id, u.public_handle AS handle, p.ridden_on,
                    COUNT(*) AS passes_that_day
               FROM game_edge_pass p
               JOIN users u ON u.id = p.user_id
              WHERE p.invalidated_at IS NULL
              GROUP BY p.user_id, p.ridden_on, u.public_handle
             HAVING COUNT(*) > ?
              ORDER BY passes_that_day DESC, p.user_id ASC
              LIMIT ?'
        );
        $stmt->bindValue(1, $threshold, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'user_id'         => (int)$r['user_id'],
                'handle'          => $r['handle'] !== null ? (string)$r['handle'] : null,
                'passes_that_day' => (int)$r['passes_that_day'],
                'ridden_on'       => (string)$r['ridden_on'],
            ];
        }
        return $out;
    }

    /**
     * Pässe mit auffällig hoher Ø-Kanten-Geschwindigkeit (> mod_max_speed_kmh,
     * unter der harten auth_max_speed_kmh). avg_speed_kmh wird beim Ingest je Pass
     * gespeichert (Segment-Speed-Feature, Migration 0032); nur nicht-invalidierte
     * Pässe zählen. Markiert nur — invalidiert NICHT automatisch.
     *
     * @return list<array{user_id:int,handle:?string,edge_id:int,route_id:int,ridden_on:string,avg_speed_kmh:float}>
     */
    public function suspiciousSpeed(int $limit = 50): array
    {
        $threshold = $this->config->float('mod_max_speed_kmh');
        $stmt = $this->pdo->prepare(
            'SELECT p.user_id, u.public_handle AS handle, p.edge_id, p.route_id,
                    p.ridden_on, p.avg_speed_kmh
               FROM game_edge_pass p
               JOIN users u ON u.id = p.user_id
              WHERE p.invalidated_at IS NULL
                AND p.avg_speed_kmh IS NOT NULL
                AND p.avg_speed_kmh > ?
              ORDER BY p.avg_speed_kmh DESC, p.user_id ASC
              LIMIT ?'
        );
        $stmt->bindValue(1, $threshold);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'user_id'       => (int)$r['user_id'],
                'handle'        => $r['handle'] !== null ? (string)$r['handle'] : null,
                'edge_id'       => (int)$r['edge_id'],
                'route_id'      => (int)$r['route_id'],
                'ridden_on'     => (string)$r['ridden_on'],
                'avg_speed_kmh' => (float)$r['avg_speed_kmh'],
            ];
        }
        return $out;
    }
}
