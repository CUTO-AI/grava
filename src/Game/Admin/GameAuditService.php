<?php
declare(strict_types=1);
namespace App\Game\Admin;

use PDO;

/** Audit-Log für alle schreibenden Admin-Aktionen. */
final class GameAuditService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed>|null $detail */
    public function record(int $adminUserId, string $action, ?string $target = null, ?array $detail = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_audit (admin_user_id, action, target, detail_json) VALUES (?, ?, ?, ?)'
        )->execute([
            $adminUserId,
            $action,
            $target,
            $detail !== null ? json_encode($detail, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    /**
     * Durchsuchbare Audit-Sicht (Backoffice, GameAdmin_Concept.md Phase 0). Filtert
     * optional nach Admin-E-Mail (Teiltext), Aktion (Teiltext) und Zeitraum; joint
     * die Admin-E-Mail dazu. Paginierbar über limit/offset.
     *
     * @return list<array<string,mixed>>
     */
    public function search(
        ?string $adminEmail = null,
        ?string $action = null,
        ?string $since = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $where = [];
        $params = [];
        if ($adminEmail !== null && $adminEmail !== '') {
            $where[] = 'u.email LIKE ?';
            $params[] = '%' . $adminEmail . '%';
        }
        if ($action !== null && $action !== '') {
            $where[] = 'a.action LIKE ?';
            $params[] = '%' . $action . '%';
        }
        if ($since !== null && $since !== '') {
            $where[] = 'a.created_at >= ?';
            $params[] = $since;
        }
        $sql = 'SELECT a.id, a.admin_user_id, u.email AS admin_email, a.action, a.target,
                       a.detail_json, a.created_at
                  FROM game_audit a
                  LEFT JOIN users u ON u.id = a.admin_user_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ? OFFSET ?';
        $stmt = $this->pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue($i, max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['detail'] = $r['detail_json'] !== null ? json_decode((string)$r['detail_json'], true) : null;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Audit-Zeilen zu einem bestimmten Ziel (z. B. einer User-ID), neueste zuerst.
     * @return list<array<string,mixed>>
     */
    public function forTarget(string $target, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.admin_user_id, u.email AS admin_email, a.action, a.target,
                    a.detail_json, a.created_at
               FROM game_audit a
               LEFT JOIN users u ON u.id = a.admin_user_id
              WHERE a.target = ? ORDER BY a.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $target);
        $stmt->bindValue(2, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['detail'] = $r['detail_json'] !== null ? json_decode((string)$r['detail_json'], true) : null;
            $out[] = $r;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> letzte N Audit-Zeilen (neueste zuerst), detail_json dekodiert. */
    public function recent(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, admin_user_id, action, target, detail_json, created_at
               FROM game_audit ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['detail'] = $r['detail_json'] !== null ? json_decode((string)$r['detail_json'], true) : null;
            $out[] = $r;
        }
        return $out;
    }
}
