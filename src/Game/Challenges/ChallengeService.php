<?php
declare(strict_types=1);

namespace App\Game\Challenges;

use App\Support\Clock;
use DateTimeImmutable;
use PDO;

/**
 * Aufgaben/Challenges (GAME_CHALLENGES_BACKEND.md, Phase C) — v1.
 *
 * Bewusst zustandslos: Der Fortschritt wird je Anfrage LIVE aus dem
 * Ereignis-Strom (game_event, Phase A) der laufenden ISO-Woche gezählt. Damit
 * ist AC2 (idempotent bei Re-Ingest) ohne eigene Zähl-Persistenz erfüllt — ein
 * erneuter Ingest legt dieselben game_event-Zeilen (UNIQUE-dedupliziert), die
 * Zählung ändert sich nicht.
 *
 * Katalog v1 (global, für alle gleich): zwei Wochen-Aufgaben, beide aus dem
 * Strom ableitbar. `points_total` = Summe der Belohnungen aktuell erledigter
 * Aufgaben (Live-Sicht). Persistente Punkte-Akkumulation, Abzeichen-Vergabe und
 * die challenge_done-Mitteilung (AC3 Persistenz) sind bewusst zurückgestellt.
 */
final class ChallengeService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array{challenges:list<array<string,mixed>>,points_total:int}
     */
    public function forUser(int $userId, string $lang): array
    {
        $de = self::normalizeLang($lang) === 'de';

        $now    = Clock::nowUtc();
        $monday = self::mondayOf($now);
        $expiresAt = $monday->modify('+7 days')->format('Y-m-d\TH:i:s\Z');
        $mondayDate = $monday->format('Y-m-d');

        $newEdges = $this->countEvents('edge_new', 'user_id', $userId, $mondayDate);
        $captures = $this->countEvents('edge_taken', 'actor_user_id', $userId, $mondayDate);
        // Gebiets-Eroberung: aktueller Besitz (Snapshot) des effektiven Claimants
        // (Crew, wenn Mitglied — sonst Solo). Robust für Solo UND Crew, da nicht
        // vom (crew-only) region_taken-Ereignis abhängig.
        $muniHeld     = $this->countOwnedRegions($userId, 8);
        $districtHeld = $this->countOwnedRegions($userId, 6);

        $challenges = [
            $this->buildChallenge(
                id: 'weekly_new_edges',
                title: $de ? 'Erschließe 5 neue Kanten' : 'Discover 5 new edges',
                detail: $de ? 'Diese Woche' : 'This week',
                progress: $newEdges,
                target: 5,
                rewardPoints: 50,
                badge: $de ? 'Entdecker' : 'Explorer',
                icon: 'map',
                expiresAt: $expiresAt,
            ),
            $this->buildChallenge(
                id: 'weekly_capture',
                title: $de ? 'Übernimm 3 Kanten' : 'Capture 3 edges',
                detail: $de ? 'Diese Woche' : 'This week',
                progress: $captures,
                target: 3,
                rewardPoints: 30,
                badge: $de ? 'Eroberer' : 'Conqueror',
                icon: 'flag',
                expiresAt: $expiresAt,
            ),
            $this->buildChallenge(
                id: 'weekly_hold_municipality',
                title: $de ? 'Halte eine Gemeinde' : 'Hold a municipality',
                detail: $de ? 'Aktueller Besitz' : 'Current holdings',
                progress: $muniHeld,
                target: 1,
                rewardPoints: 40,
                badge: $de ? 'Stadtherr' : 'City Holder',
                icon: 'building.2',
                expiresAt: $expiresAt,
            ),
            $this->buildChallenge(
                id: 'weekly_hold_district',
                title: $de ? 'Halte einen Landkreis' : 'Hold a district',
                detail: $de ? 'Aktueller Besitz' : 'Current holdings',
                progress: $districtHeld,
                target: 1,
                rewardPoints: 80,
                badge: $de ? 'Landvogt' : 'District Lord',
                icon: 'mappin.and.ellipse',
                expiresAt: $expiresAt,
            ),
        ];

        $pointsTotal = 0;
        foreach ($challenges as $c) {
            if ($c['progress'] >= $c['target']) {
                $pointsTotal += (int)$c['reward_points'];
                // Abschluss festhalten (idempotent), speist die Challenger-
                // Abzeichen-Familie (§5.2). Lazy-on-read: greift, wenn der Nutzer
                // die Aufgaben in der erfüllten Woche ansieht.
                $this->recordCompletion($userId, (string)$c['id'], $mondayDate);
            }
        }

        return ['challenges' => $challenges, 'points_total' => $pointsTotal];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildChallenge(
        string $id,
        string $title,
        string $detail,
        int $progress,
        int $target,
        int $rewardPoints,
        string $badge,
        string $icon,
        string $expiresAt,
    ): array {
        return [
            'id'            => $id,
            'title'         => $title,
            'detail'        => $detail,
            'progress'      => min($progress, $target), // Anzeige nie über dem Ziel
            'target'        => $target,
            'reward_points' => $rewardPoints,
            'badge'         => $badge,
            'icon'          => $icon,
            'expires_at'    => $expiresAt,
            'period'        => 'weekly',
        ];
    }

    /**
     * Zählt distinct Kanten eines Ereignistyps für den Nutzer ab dem Wochen-
     * Montag (ridden_on). $userColumn ist user_id (Betroffener) oder
     * actor_user_id (Auslöser), je nach Aufgabe.
     */
    private function countEvents(string $type, string $userColumn, int $userId, string $sinceDate): int
    {
        $col = $userColumn === 'actor_user_id' ? 'actor_user_id' : 'user_id';
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT edge_id)
               FROM game_event
              WHERE type = ? AND {$col} = ? AND edge_id IS NOT NULL AND ridden_on >= ?"
        );
        $stmt->execute([$type, $userId, $sinceDate]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Aktuell gehaltene (nicht umkämpfte) Gebiete einer Ebene für den effektiven
     * Claimant des Nutzers. Graceful: fehlen die Gebiets-Tabellen, → 0.
     */
    private function countOwnedRegions(int $userId, int $level): int
    {
        $claimantId = $this->effectiveClaimantId($userId);
        if ($claimantId === null) {
            return 0;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*)
                   FROM game_region_ownership o
                   JOIN game_region r ON r.id = o.region_id
                  WHERE o.owner_claimant_id = ? AND o.contested = 0 AND r.level = ?'
            );
            $stmt->execute([$claimantId, $level]);
            return (int)$stmt->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    /** Effektiver Claimant: Crew-Claimant (wenn Mitglied), sonst Rider-Claimant. */
    private function effectiveClaimantId(int $userId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.claimant_id
               FROM game_crew_member m
               JOIN game_crew cr ON cr.id = m.crew_id
              WHERE m.user_id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $crew = $stmt->fetchColumn();
        if ($crew !== false && $crew !== null) {
            return (int)$crew;
        }
        $stmt = $this->pdo->prepare("SELECT id FROM game_claimant WHERE type = 'rider' AND user_id = ?");
        $stmt->execute([$userId]);
        $rider = $stmt->fetchColumn();
        return $rider === false || $rider === null ? null : (int)$rider;
    }

    /**
     * Hält den Abschluss einer Challenge fest (idempotent über den Primär-
     * schlüssel (user, challenge, Woche)). Best effort — ein Fehler hier darf
     * die Aufgaben-Anzeige nicht stören.
     */
    private function recordCompletion(int $userId, string $challengeId, string $periodStart): void
    {
        try {
            $this->pdo->prepare(
                'INSERT IGNORE INTO game_challenge_completion (user_id, challenge_id, period_start)
                 VALUES (?, ?, ?)'
            )->execute([$userId, $challengeId, $periodStart]);
        } catch (\PDOException) {
            // Tabelle (noch) nicht migriert o. Ä. — Anzeige bleibt funktionsfähig.
        }
    }

    /** Montag (00:00 UTC) der ISO-Woche von $dt. */
    private static function mondayOf(DateTimeImmutable $dt): DateTimeImmutable
    {
        $offset = (int)$dt->format('N') - 1;
        return $dt->setTime(0, 0, 0)->modify("-{$offset} days");
    }

    /** 'de' für deutschsprachige Accept-Language-Header, sonst 'en'. */
    private static function normalizeLang(string $acceptLanguage): string
    {
        return stripos(trim($acceptLanguage), 'de') === 0 ? 'de' : 'en';
    }
}
