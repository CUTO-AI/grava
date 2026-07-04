<?php
declare(strict_types=1);

namespace App\Game;

use App\Presence\PresenceService;
use App\Support\Clock;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * PulseService — öffentliches „Heute im Spiel"-Aggregat für die /pulse-Webseite.
 *
 * Bündelt zehn Live-Kennzahlen aus dem gemeinsamen Spiel-Datenbestand:
 * Tagesbericht der Eroberungen, Team-Rangliste, Fraktionsstand, neue Rekorde,
 * Pioniere, Tages-Kennzahlen, umkämpfteste Region und ein Live-Ereignis-Feed.
 *
 * „Heute" = Kalendertag UTC (wie CommunityTodayService). Jede Sektion ist
 * defensiv gekapselt: schlägt eine Query fehl (z. B. weil eine Tabelle in einer
 * Umgebung fehlt), liefert nur diese Sektion leer/neutral — die Seite bleibt heil.
 * Es werden ausschließlich aggregierte, anonyme Werte ausgegeben (wie die Heatmap).
 */
final class PulseService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PresenceService $presence,
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::nowUtc();
        $start = $now->setTime(0, 0, 0);
        $s = $start->format('Y-m-d H:i:s');
        $e = $start->modify('+1 day')->format('Y-m-d H:i:s');

        return [
            'generated_at' => $now->format(DATE_ATOM),
            'day'          => $start->format('Y-m-d'),
            'live'         => ['active_now' => $this->safe(fn() => $this->presence->activeCount(), 0)],
            'today'        => $this->safe(fn() => $this->todayStats($s, $e), []),
            'factions'     => $this->safe(fn() => $this->factions(), []),
            'region_report'=> $this->safe(fn() => $this->regionReport($s, $e), []),
            'team_ranking' => $this->safe(fn() => $this->teamRanking($s, $e), []),
            'records'      => $this->safe(fn() => $this->records($s, $e), []),
            'pioneers'     => $this->safe(fn() => $this->pioneers($s, $e), []),
            'hot_regions'  => $this->safe(fn() => $this->hotRegions($s, $e), []),
            'feed'         => $this->safe(fn() => $this->feed($s, $e), []),
        ];
    }

    /**
     * @template T
     * @param callable():T $fn
     * @param T $fallback
     * @return T
     */
    private function safe(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** SQL-Fragment: Anzeigename + Fraktionsfarbe eines Claimants (Alias `cl`). */
    private const CLAIMANT_JOIN = '
        LEFT JOIN game_crew    cw ON cw.claimant_id = cl.id
        LEFT JOIN users        cu ON cu.id = cl.user_id
        LEFT JOIN game_faction cf ON cf.id = cw.faction_id';
    private const CLAIMANT_NAME =
        "COALESCE(cw.name, cu.display_name, cu.public_handle, CONCAT('Rider #', cl.id))";

    /** #7 Tages-Kennzahlen: Fahrten, km, Höhenmeter, Anmeldungen, Eroberungen … */
    private function todayStats(string $s, string $e): array
    {
        $rides = $this->pdo->prepare(
            'SELECT COUNT(*) c, COALESCE(SUM(distance_m),0) dist, COALESCE(SUM(elevation_gain_m),0) elev
               FROM routes WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?'
        );
        $rides->execute([$s, $e]);
        $r = $rides->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'rides'          => (int)($r['c'] ?? 0),
            'distance_km'    => round((float)($r['dist'] ?? 0) / 1000, 1),
            'elevation_m'    => (int)round((float)($r['elev'] ?? 0)),
            'signups'        => $this->scalar('SELECT COUNT(*) FROM users WHERE created_at >= ? AND created_at < ?', [$s, $e]),
            'regions_taken'  => $this->scalar('SELECT COUNT(*) FROM game_region_ownership WHERE owner_claimant_id IS NOT NULL AND owner_since >= ? AND owner_since < ?', [$s, $e]),
            'edges_taken'    => $this->scalar('SELECT COUNT(*) FROM game_edge WHERE owner_since >= ? AND owner_since < ?', [$s, $e]),
            'edges_new'      => $this->scalar('SELECT COUNT(*) FROM game_edge WHERE discovered_at >= ? AND discovered_at < ?', [$s, $e]),
            'records_beaten' => $this->scalar("SELECT COUNT(*) FROM game_event WHERE type='record_beaten' AND created_at >= ? AND created_at < ?", [$s, $e]),
        ];
    }

    /** #4 Fraktions-Kräftemessen: gehaltene Fläche/Kanten je Fraktion (gesamt). */
    private function factions(): array
    {
        $rows = $this->pdo->query(
            'SELECT f.key_slug, f.name, f.color_hex,
                    COUNT(e.id) edges, COALESCE(SUM(e.length_m),0) len,
                    COUNT(DISTINCT cr.id) crews
               FROM game_faction f
               LEFT JOIN game_crew cr     ON cr.faction_id = f.id
               LEFT JOIN game_claimant c  ON c.id = cr.claimant_id AND c.type = "group"
               LEFT JOIN game_edge e      ON e.owner_claimant_id = c.id
              GROUP BY f.id, f.key_slug, f.name, f.color_hex
              ORDER BY len DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $total = 0.0;
        foreach ($rows as $r) { $total += (float)$r['len']; }

        $out = [];
        foreach ($rows as $r) {
            $len = (float)$r['len'];
            $out[] = [
                'key'          => (string)$r['key_slug'],
                'name'         => (string)$r['name'],
                'color'        => (string)$r['color_hex'],
                'edges'        => (int)$r['edges'],
                'crews'        => (int)$r['crews'],
                'held_length_km' => round($len / 1000, 1),
                'share'        => $total > 0 ? round($len / $total, 4) : 0,
            ];
        }
        return $out;
    }

    /** #1 Tagesbericht: heute (neu) eroberte Gebiete, jüngste zuerst. */
    private function regionReport(string $s, string $e): array
    {
        $q = $this->pdo->prepare(
            'SELECT r.name region_name, ro.region_id, ro.held_fraction, ro.owner_since,
                    ' . self::CLAIMANT_NAME . ' owner_name, cl.type owner_type,
                    cf.color_hex faction_color, cf.key_slug faction_key
               FROM game_region_ownership ro
               JOIN game_region r      ON r.id = ro.region_id
               JOIN game_claimant cl   ON cl.id = ro.owner_claimant_id'
            . self::CLAIMANT_JOIN .
            ' WHERE ro.owner_claimant_id IS NOT NULL AND ro.owner_since >= ? AND ro.owner_since < ?
              ORDER BY ro.owner_since DESC
              LIMIT 25'
        );
        $q->execute([$s, $e]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'region'        => (string)$r['region_name'],
                'region_id'     => (int)$r['region_id'],
                'owner'         => (string)$r['owner_name'],
                'owner_type'    => (string)$r['owner_type'],
                'faction_color' => $r['faction_color'] ?: null,
                'faction_key'   => $r['faction_key'] ?: null,
                'held_fraction' => round((float)$r['held_fraction'], 3),
                'at'            => $this->iso($r['owner_since']),
            ];
        }
        return $out;
    }

    /** #2 / #9 Team-Rangliste: meiste heute eroberte Kanten je Claimant. */
    private function teamRanking(string $s, string $e): array
    {
        $q = $this->pdo->prepare(
            'SELECT COUNT(*) edges, COALESCE(SUM(e.length_m),0) len,
                    ' . self::CLAIMANT_NAME . ' name, cl.type type,
                    cf.color_hex faction_color, cf.key_slug faction_key
               FROM game_edge e
               JOIN game_claimant cl ON cl.id = e.owner_claimant_id'
            . self::CLAIMANT_JOIN .
            ' WHERE e.owner_since >= ? AND e.owner_since < ?
              GROUP BY e.owner_claimant_id, name, cl.type, faction_color, faction_key
              ORDER BY edges DESC, len DESC
              LIMIT 8'
        );
        $q->execute([$s, $e]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'name'          => (string)$r['name'],
                'type'          => (string)$r['type'],
                'edges'         => (int)$r['edges'],
                'length_km'     => round((float)$r['len'] / 1000, 1),
                'faction_color' => $r['faction_color'] ?: null,
                'faction_key'   => $r['faction_key'] ?: null,
            ];
        }
        return $out;
    }

    /** #5 Neue Rekorde heute (record_beaten-Ereignisse). */
    private function records(string $s, string $e): array
    {
        $q = $this->pdo->prepare(
            "SELECT ev.edge_id, ev.created_at,
                    COALESCE(cw.name, u.display_name, u.public_handle, CONCAT('Rider #', u.id)) actor,
                    reg.name region_name
               FROM game_event ev
               LEFT JOIN users u             ON u.id = ev.actor_user_id
               LEFT JOIN game_crew_member m  ON m.user_id = ev.actor_user_id
               LEFT JOIN game_crew cw        ON cw.id = m.crew_id
               LEFT JOIN game_edge ge        ON ge.id = ev.edge_id
               LEFT JOIN game_region reg     ON reg.id = ge.region_id
              WHERE ev.type = 'record_beaten' AND ev.created_at >= ? AND ev.created_at < ?
              ORDER BY ev.created_at DESC
              LIMIT 10"
        );
        $q->execute([$s, $e]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'actor'  => (string)($r['actor'] ?? '—'),
                'region' => $r['region_name'] ?: null,
                'at'     => $this->iso($r['created_at']),
            ];
        }
        return $out;
    }

    /** #6 Pioniere heute: neu entdeckte Kanten je Erst-Befahrer. */
    private function pioneers(string $s, string $e): array
    {
        $q = $this->pdo->prepare(
            'SELECT COUNT(*) edges,
                    ' . self::CLAIMANT_NAME . ' name, cl.type type, cf.color_hex faction_color
               FROM game_edge e
               JOIN game_claimant cl ON cl.id = e.discoverer_claimant_id'
            . self::CLAIMANT_JOIN .
            ' WHERE e.discovered_at >= ? AND e.discovered_at < ?
              GROUP BY e.discoverer_claimant_id, name, cl.type, faction_color
              ORDER BY edges DESC
              LIMIT 8'
        );
        $q->execute([$s, $e]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'name'          => (string)$r['name'],
                'type'          => (string)$r['type'],
                'edges'         => (int)$r['edges'],
                'faction_color' => $r['faction_color'] ?: null,
            ];
        }
        return $out;
    }

    /** #8 Umkämpfteste Regionen heute: meiste Besitzwechsel-Ereignisse. */
    private function hotRegions(string $s, string $e): array
    {
        $q = $this->pdo->prepare(
            "SELECT r.name region_name, COUNT(*) flips
               FROM game_event ev
               JOIN game_region r ON r.id = ev.region_id
              WHERE ev.type IN ('region_taken','region_lost')
                AND ev.region_id IS NOT NULL
                AND ev.created_at >= ? AND ev.created_at < ?
              GROUP BY ev.region_id, r.name
              ORDER BY flips DESC
              LIMIT 5"
        );
        $q->execute([$s, $e]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['region' => (string)$r['region_name'], 'flips' => (int)$r['flips']];
        }
        return $out;
    }

    /** #10 Live-Feed: jüngste Ereignisse aller Art (strukturiert, Text baut das JS). */
    private function feed(string $s, string $e): array
    {
        $q = $this->pdo->prepare(
            "SELECT ev.type, ev.created_at,
                    COALESCE(cw.name, u.display_name, u.public_handle, CONCAT('Rider #', u.id)) actor,
                    COALESCE(reg.name, ereg.name) region_name
               FROM game_event ev
               LEFT JOIN users u             ON u.id = ev.actor_user_id
               LEFT JOIN game_crew_member m  ON m.user_id = ev.actor_user_id
               LEFT JOIN game_crew cw        ON cw.id = m.crew_id
               LEFT JOIN game_region reg     ON reg.id = ev.region_id
               LEFT JOIN game_edge ge        ON ge.id = ev.edge_id
               LEFT JOIN game_region ereg    ON ereg.id = ge.region_id
              WHERE ev.created_at >= ? AND ev.created_at < ?
              ORDER BY ev.created_at DESC
              LIMIT 30"
        );
        $q->execute([$s, $e]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'type'   => (string)$r['type'],
                'actor'  => $r['actor'] ? (string)$r['actor'] : null,
                'region' => $r['region_name'] ?: null,
                'at'     => $this->iso($r['created_at']),
            ];
        }
        return $out;
    }

    // ---- helpers -----------------------------------------------------------

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    private function iso(mixed $dbDatetime): ?string
    {
        if (!is_string($dbDatetime) || $dbDatetime === '') { return null; }
        // DB-Zeiten sind UTC → als solche kennzeichnen (Z), das JS lokalisiert.
        return str_replace(' ', 'T', substr($dbDatetime, 0, 19)) . 'Z';
    }
}
