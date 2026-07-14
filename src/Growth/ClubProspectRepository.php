<?php
declare(strict_types=1);

namespace App\Growth;

use PDO;

/**
 * CRUD + Funnel-Zählung für die Vereins-Zielliste (club_prospect,
 * CrewInvite_Onboarding_Spec §8.3). Keine Outreach-Logik (Versand/Tracking) —
 * die kommt in einem eigenen Service, sobald der E-Mail-/Tracking-Teil dran ist.
 */
final class ClubProspectRepository
{
    /** Kanonische Funnel-Reihenfolge (auch für die Board-Spalten). */
    public const STATUSES = ['prospect', 'invited', 'delivered', 'email_opened', 'link_opened', 'activated', 'playing', 'declined'];

    private const FIELDS = [
        'name', 'landkreis', 'discipline', 'contact_email', 'official_source_url',
        'register_court', 'register_no', 'is_charitable', 'status', 'assigned_to', 'notes',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /** Normalisierter Dedup-Schlüssel (Name + Landkreis), klein/getrimmt. */
    public static function dedupKey(string $name, ?string $landkreis): string
    {
        $norm = static fn (string $s): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $s) ?? $s));
        return $norm($name) . '|' . $norm((string)$landkreis);
    }

    /**
     * Legt einen Prospect an; bei Kollision auf dem Dedup-Schlüssel wird der
     * bestehende Datensatz aktualisiert (für die Eingabemaske: idempotent).
     *
     * @param array<string,mixed> $data
     */
    public function upsert(array $data): int
    {
        $key = self::dedupKey((string)($data['name'] ?? ''), $data['landkreis'] ?? null);
        $existing = $this->byDedupKey($key);
        if ($existing !== null) {
            $this->update((int)$existing['id'], $data);
            return (int)$existing['id'];
        }
        $cols = ['dedup_key'];
        $ph   = ['?'];
        $vals = [$key];
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $data)) {
                $cols[] = $f;
                $ph[]   = '?';
                $vals[] = $f === 'is_charitable' ? (!empty($data[$f]) ? 1 : 0) : $data[$f];
            }
        }
        $sql = 'INSERT INTO club_prospect (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
        $this->pdo->prepare($sql)->execute($vals);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $set = [];
        $vals = [];
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $data)) {
                $set[] = "$f = ?";
                $vals[] = $f === 'is_charitable' ? (!empty($data[$f]) ? 1 : 0) : $data[$f];
            }
        }
        if ($set === []) {
            return;
        }
        // Dedup-Key mitziehen, falls Name/Landkreis geändert wurden.
        if (array_key_exists('name', $data) || array_key_exists('landkreis', $data)) {
            $cur = $this->byId($id);
            if ($cur !== null) {
                $set[] = 'dedup_key = ?';
                $vals[] = self::dedupKey(
                    (string)($data['name'] ?? $cur['name']),
                    $data['landkreis'] ?? $cur['landkreis'],
                );
            }
        }
        $vals[] = $id;
        $this->pdo->prepare('UPDATE club_prospect SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    }

    /** @return array<string,mixed>|null */
    public function byId(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM club_prospect WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @return array<string,mixed>|null */
    public function byDedupKey(string $key): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM club_prospect WHERE dedup_key = ?');
        $st->execute([$key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(?string $status = null, ?string $landkreis = null, int $limit = 500): array
    {
        $where = [];
        $vals = [];
        if ($status !== null && $status !== '') {
            $where[] = 'status = ?';
            $vals[] = $status;
        }
        if ($landkreis !== null && $landkreis !== '') {
            $where[] = 'landkreis = ?';
            $vals[] = $landkreis;
        }
        $sql = 'SELECT * FROM club_prospect';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY landkreis, name LIMIT ' . max(1, min(2000, $limit));
        $st = $this->pdo->prepare($sql);
        $st->execute($vals);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function byInviteToken(string $token): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM club_prospect WHERE invite_token = ?');
        $st->execute([$token]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** Markiert einen Prospect als eingeladen (Token gesetzt, Status/Zeitstempel). */
    public function setInvited(int $id, string $token, string $invitedAt): void
    {
        $this->pdo->prepare(
            "UPDATE club_prospect SET invite_token = ?, status = 'invited', invited_at = ? WHERE id = ?"
        )->execute([$token, $invitedAt, $id]);
    }

    /** Link geöffnet (Aktivierungslink abgerufen); hebt den Status nur vorwärts. */
    public function markLinkOpenedByToken(string $token, string $at): void
    {
        $this->pdo->prepare(
            "UPDATE club_prospect
                SET link_opened_at = COALESCE(link_opened_at, ?),
                    status = CASE WHEN status IN ('prospect','invited','delivered','email_opened')
                                  THEN 'link_opened' ELSE status END
              WHERE invite_token = ?"
        )->execute([$at, $token]);
    }

    /** Aktiviert (verifizierte Crew erzeugt) → Endstufe verknüpfen. */
    public function markActivatedByToken(string $token, int $crewId, string $at): void
    {
        $this->pdo->prepare(
            "UPDATE club_prospect
                SET activated_at = COALESCE(activated_at, ?), crew_id = ?, status = 'activated'
              WHERE invite_token = ?"
        )->execute([$at, $crewId, $token]);
    }

    /** @return array<string,int> Anzahl je Status (Funnel-Übersicht). */
    public function statusCounts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($this->pdo->query('SELECT status, COUNT(*) c FROM club_prospect GROUP BY status') as $r) {
            $out[(string)$r['status']] = (int)$r['c'];
        }
        return $out;
    }
}
