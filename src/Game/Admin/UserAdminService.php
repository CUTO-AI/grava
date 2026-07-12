<?php
declare(strict_types=1);

namespace App\Game\Admin;

use App\Support\Clock;
use PDO;

/**
 * Nutzerverwaltung fürs Backoffice (GameAdmin_Concept.md Modul B): Suche, 360°-
 * Detail und Support-Aktionen (Verify erzwingen, Profil ändern, DSGVO-
 * Anonymisierung). Ban/Unban bleibt im {@see GameUserFlagService}; Spielwerte
 * kommen aus {@see GameAdminService::playerDetail()}.
 */
final class UserAdminService
{
    /** erlaubte Sortierspalten (Whitelist für ORDER BY) */
    public const SORTS = ['created_at', 'email', 'id'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly GameAdminService $game,
    ) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function search(string $q, string $sort, string $dir, int $limit, int $offset): array
    {
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'created_at';
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $where = '';
        $params = [];
        if ($q !== '') {
            $where = 'WHERE (u.email LIKE ? OR u.public_handle LIKE ? OR u.display_name LIKE ? OR u.public_id = ? OR u.id = ?)';
            $like = '%' . $q . '%';
            $params = [$like, $like, $like, $q, ctype_digit($q) ? (int)$q : 0];
        }
        $sql = "SELECT u.id, u.public_id, u.email, u.public_handle AS handle, u.display_name,
                       u.status, u.email_verified_at, u.created_at,
                       COALESCE(f.banned, 0) AS banned
                  FROM users u
                  LEFT JOIN game_user_flag f ON f.user_id = u.id
                  {$where}
                  ORDER BY u.{$sort} {$dir}
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

    /** @return array<string,mixed>|null 360°-Sicht oder null, wenn User fehlt. */
    public function detail(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.public_id, u.email, u.public_handle AS handle, u.display_name,
                    u.status, u.email_verified_at, u.created_at, u.updated_at,
                    COALESCE(f.banned, 0) AS banned, f.reason AS ban_reason
               FROM users u
               LEFT JOIN game_user_flag f ON f.user_id = u.id
              WHERE u.id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            return null;
        }

        $ridesTotal = (int)$this->scalar('SELECT COUNT(*) FROM routes WHERE user_id = ? AND deleted_at IS NULL', [$userId]);
        $ridesGame = (int)$this->scalar(
            'SELECT COUNT(DISTINCT r.id) FROM routes r
               JOIN game_edge_pass gp ON gp.route_id = r.id AND gp.user_id = r.user_id AND gp.invalidated_at IS NULL
              WHERE r.user_id = ? AND r.deleted_at IS NULL',
            [$userId],
        );
        $stravaLinked = (int)$this->scalar('SELECT COUNT(*) FROM strava_accounts WHERE user_id = ?', [$userId]) > 0;

        // Spielwerte (Revierlänge/Rang/Crew/Fraktion …) best-effort über die
        // vorhandene Admin-Player-Sicht; Signatur = Suchbegriff (E-Mail/Handle/ID).
        $game = null;
        try {
            $game = $this->game->playerDetail((string)$user['id']);
        } catch (\Throwable) {
            $game = null;
        }

        return [
            'user'         => $user,
            'rides_total'  => $ridesTotal,
            'rides_game'   => $ridesGame,
            'strava'       => $stravaLinked,
            'game'         => $game,
        ];
    }

    public function forceVerify(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET email_verified_at = ? WHERE id = ? AND email_verified_at IS NULL')
            ->execute([Clock::nowUtcString(), $userId]);
    }

    /** Ändert Anzeigename/Handle. Gibt false bei Handle-Kollision. */
    public function setProfile(int $userId, ?string $displayName, ?string $handle): bool
    {
        if ($handle !== null && $handle !== '') {
            $exists = $this->scalar('SELECT id FROM users WHERE public_handle = ? AND id <> ?', [$handle, $userId]);
            if ($exists !== null && $exists !== false) {
                return false;
            }
        }
        $this->pdo->prepare(
            'UPDATE users SET display_name = ?, public_handle = ?, updated_at = ? WHERE id = ?'
        )->execute([
            $displayName !== '' ? $displayName : null,
            $handle !== '' ? $handle : null,
            Clock::nowUtcString(),
            $userId,
        ]);
        return true;
    }

    /**
     * DSGVO-Anonymisierung: markiert den Account als gelöscht und entfernt PII.
     * Fahrten/Spielverlauf bleiben (aggregierte Spielintegrität), aber ohne
     * Personenbezug. Nur super.
     */
    public function anonymize(int $userId): void
    {
        $anon = 'deleted+' . $userId . '@anon.invalid';
        $this->pdo->prepare(
            "UPDATE users
                SET status = 'deleted', deleted_at = ?, email = ?, display_name = NULL,
                    public_handle = NULL, updated_at = ?
              WHERE id = ?"
        )->execute([Clock::nowUtcString(), $anon, Clock::nowUtcString(), $userId]);
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
            return 0;   // fehlende Tabelle/Spalte → neutral
        }
    }
}
