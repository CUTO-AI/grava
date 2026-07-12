<?php
declare(strict_types=1);

namespace App\Game\Admin;

use App\Push\ApnsTransport;
use App\Support\Clock;
use PDO;

/**
 * Broadcast-Push (GameAdmin_Concept.md Phase 2): admin-erstellte Mitteilungen an
 * ein Nutzersegment. Erstellung reiht als `queued` ein; der Cron-Worker
 * (game:broadcast-run) sendet via {@see ApnsTransport} und schreibt Zähler zurück.
 * Gebannte + nicht-aktive Nutzer sind immer ausgeschlossen.
 */
final class BroadcastService
{
    public const SEGMENTS = ['all', 'active_7d', 'active_30d'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ApnsTransport $transport,
    ) {}

    /** WHERE-Fragment + Params für das Zielsegment (Basis: aktive, nicht gebannte User). */
    private function segmentWhere(string $segment): array
    {
        $where = "u.status = 'active' AND COALESCE(f.banned, 0) = 0";
        $params = [];
        if ($segment === 'active_7d' || $segment === 'active_30d') {
            $days = $segment === 'active_7d' ? 7 : 30;
            $since = Clock::nowUtc()->modify("-{$days} days")->format('Y-m-d H:i:s');
            $where .= ' AND EXISTS(SELECT 1 FROM routes r WHERE r.user_id = u.id AND r.deleted_at IS NULL AND r.created_at >= ?)';
            $params[] = $since;
        }
        return [$where, $params];
    }

    /** Geschätzte Empfänger (distinct User mit Gerät im Segment). */
    public function estimate(string $segment): int
    {
        [$where, $params] = $this->segmentWhere($segment);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT u.id)
               FROM users u
               JOIN push_devices d ON d.user_id = u.id
               LEFT JOIN game_user_flag f ON f.user_id = u.id
              WHERE {$where}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function queue(?int $adminId, string $title, string $body, ?string $deeplink, string $segment): int
    {
        if (!in_array($segment, self::SEGMENTS, true)) {
            $segment = 'all';
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_broadcasts (created_by, title, body, deeplink, segment, status, recipients)
             VALUES (?, ?, ?, ?, ?, \'queued\', ?)'
        );
        $stmt->execute([
            $adminId, mb_substr($title, 0, 120), mb_substr($body, 0, 300),
            $deeplink !== null && $deeplink !== '' ? mb_substr($deeplink, 0, 200) : null,
            $segment, $this->estimate($segment),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, u.email AS admin_email FROM admin_broadcasts b
               LEFT JOIN users u ON u.id = b.created_by
              ORDER BY b.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Verarbeitet den nächsten wartenden Broadcast: sendet an alle Zielgeräte und
     * schreibt Status/Zähler. Gibt null zurück, wenn nichts ansteht.
     *
     * @return array{id:int,sent:int}|null
     */
    public function runNext(): ?array
    {
        // Atomar einen queued-Broadcast auf sending setzen.
        $this->pdo->beginTransaction();
        try {
            $sel = $this->pdo->query(
                "SELECT * FROM admin_broadcasts WHERE status = 'queued' ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED"
            );
            $bc = $sel->fetch(PDO::FETCH_ASSOC);
            if ($bc === false) {
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare("UPDATE admin_broadcasts SET status = 'sending' WHERE id = ?")
                ->execute([(int)$bc['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $payload = [
            'aps' => [
                'alert' => ['title' => (string)$bc['title'], 'body' => (string)$bc['body']],
                'sound' => 'default',
            ],
            'broadcast' => '1',
        ];
        if (($bc['deeplink'] ?? null) !== null && $bc['deeplink'] !== '') {
            $payload['url'] = (string)$bc['deeplink'];
        }

        [$where, $params] = $this->segmentWhere((string)$bc['segment']);
        $stmt = $this->pdo->prepare(
            "SELECT d.token, d.environment
               FROM push_devices d
               JOIN users u ON u.id = d.user_id
               LEFT JOIN game_user_flag f ON f.user_id = u.id
              WHERE {$where}"
        );
        $stmt->execute($params);

        $sent = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $dev) {
            try {
                $status = $this->transport->send((string)$dev['environment'], (string)$dev['token'], $payload, 'broadcast-' . (int)$bc['id']);
                if ($status >= 200 && $status < 300) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                error_log('broadcast send failed: ' . $e->getMessage());
            }
        }

        $this->pdo->prepare(
            "UPDATE admin_broadcasts SET status = 'sent', sent = ?, sent_at = ? WHERE id = ?"
        )->execute([$sent, Clock::nowUtcString(), (int)$bc['id']]);

        return ['id' => (int)$bc['id'], 'sent' => $sent];
    }
}
