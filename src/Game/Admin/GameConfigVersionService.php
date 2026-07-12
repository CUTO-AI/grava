<?php
declare(strict_types=1);

namespace App\Game\Admin;

use App\Support\Clock;
use PDO;

/**
 * Voll-Snapshots der Spiel-Config (GameAdmin_Concept.md Phase 2): Historie, Diff
 * und Rollback. Bei jeder ändernden Speicherung legt {@see GameConfigAdminService}
 * hier den resultierenden Gesamtzustand ab. Reines Lesen/Schreiben — das Anwenden
 * eines Rollbacks orchestriert der Controller über GameConfigAdminService::update().
 */
final class GameConfigVersionService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array<string,string> $values vollständiger Config-Zustand nach der Änderung
     */
    public function record(?int $adminUserId, array $values, string $note = ''): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_config_versions (created_by, created_at, note, snapshot_json)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $adminUserId,
            Clock::nowUtcString(),
            $note !== '' ? mb_substr($note, 0, 160) : null,
            (string)json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return list<array{id:int,created_by:?int,created_at:string,note:?string,admin_email:?string}> */
    public function listVersions(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.id, v.created_by, v.created_at, v.note, u.email AS admin_email
               FROM game_config_versions v
               LEFT JOIN users u ON u.id = v.created_by
              ORDER BY v.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'          => (int)$r['id'],
                'created_by'  => $r['created_by'] !== null ? (int)$r['created_by'] : null,
                'created_at'  => (string)$r['created_at'],
                'note'        => $r['note'] !== null ? (string)$r['note'] : null,
                'admin_email' => $r['admin_email'] !== null ? (string)$r['admin_email'] : null,
            ];
        }
        return $out;
    }

    /** @return array{id:int,created_at:string,note:?string,values:array<string,string>}|null */
    public function get(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, created_at, note, snapshot_json FROM game_config_versions WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        $values = json_decode((string)$r['snapshot_json'], true);
        return [
            'id'         => (int)$r['id'],
            'created_at' => (string)$r['created_at'],
            'note'       => $r['note'] !== null ? (string)$r['note'] : null,
            'values'     => is_array($values) ? $values : [],
        ];
    }

    /**
     * Diff einer Version gegen die unmittelbar vorherige (kleinere id). Liefert je
     * geändertem Key [before, after]. Ohne Vorversion = alle Keys als „neu".
     *
     * @return array<string,array{before:?string,after:?string}>
     */
    public function diffToPrevious(int $id): array
    {
        $cur = $this->get($id);
        if ($cur === null) {
            return [];
        }
        $prevId = (int)($this->pdo->query('SELECT MAX(id) FROM game_config_versions WHERE id < ' . (int)$id)->fetchColumn() ?: 0);
        $prev = $prevId > 0 ? ($this->get($prevId)['values'] ?? []) : [];

        $diff = [];
        $keys = array_unique(array_merge(array_keys($prev), array_keys($cur['values'])));
        sort($keys);
        foreach ($keys as $k) {
            $before = $prev[$k] ?? null;
            $after = $cur['values'][$k] ?? null;
            if ((string)$before !== (string)$after) {
                $diff[$k] = ['before' => $before, 'after' => $after];
            }
        }
        return $diff;
    }
}
