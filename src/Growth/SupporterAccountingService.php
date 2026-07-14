<?php
declare(strict_types=1);

namespace App\Growth;

use App\Game\GameConfig;
use App\Game\RegionRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Supporter-Ökonomie (Supporter_Economy_Spec.md, A8 Stufe 2). Rechnet je Monat
 * und Landkreis: Basis (gedeckelte km→Kasse), Landkreis-Champion (größter
 * Revieranteil unter verifizierten Vereinen) und Champion-Bonus (Top-3 50/30/20).
 *
 * REINE BERECHNUNG + Snapshot — KEINE Auszahlung. Läuft nur, wenn
 * `supporter_program_enabled=1`. Nur verifizierte Vereine (§8.1), km aus
 * `routes.distance_m` (muskelbetrieben; E-Bike-Ausschluss = offenes Feld).
 */
final class SupporterAccountingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly GameConfig $config,
        private readonly RegionRepository $regions,
    ) {}

    /**
     * Berechnet die Periode und schreibt den Snapshot (supporter_month).
     *
     * @param string $period 'YYYY-MM'
     * @return array{enabled:bool,period:string,landkreise:int,clubs:int,basis_ct:int,bonus_ct:int}
     */
    public function computeAndStore(string $period): array
    {
        if (!$this->config->bool('supporter_program_enabled')) {
            return ['enabled' => false, 'period' => $period, 'landkreise' => 0, 'clubs' => 0, 'basis_ct' => 0, 'bonus_ct' => 0];
        }
        $lkIds = $this->parseLandkreise();
        if ($lkIds === []) {
            return ['enabled' => true, 'period' => $period, 'landkreise' => 0, 'clubs' => 0, 'basis_ct' => 0, 'bonus_ct' => 0];
        }

        $rateCt   = max(0, $this->config->int('supporter_km_rate_ct'));
        $weekCap  = max(1, $this->config->int('supporter_week_km_cap'));
        $potEur   = max(0, $this->config->int('supporter_bonus_pot_eur'));
        $minClubs = max(1, $this->config->int('supporter_min_clubs'));
        $budgetCt = max(0, $this->config->int('supporter_total_budget_eur')) * 100;

        [$winStart, $winEnd, $weeksInMonth] = $this->window($period);

        // Verifizierte Vereine: claimant_id → {crewId,name,memberIds}.
        $verified = $this->verifiedCrews();
        if ($verified === []) {
            return ['enabled' => true, 'period' => $period, 'landkreise' => count($lkIds), 'clubs' => 0, 'basis_ct' => 0, 'bonus_ct' => 0];
        }
        $claimantToCrew = [];
        foreach ($verified as $crewId => $c) {
            $claimantToCrew[(int)$c['claimant_id']] = $crewId;
        }

        // Territorium je (Verein, Landkreis) → Heim-Landkreis (max. Territorium).
        $territory = [];              // crewId => [lkId => len]
        $lkPath = $this->regionPaths($lkIds);
        foreach ($lkIds as $lkId) {
            $path = $lkPath[$lkId] ?? null;
            if ($path === null) {
                continue;
            }
            foreach ($this->regions->leaderboardByPathPrefix($path, 500) as $row) {
                $crewId = $claimantToCrew[$row['claimant_id']] ?? null;
                if ($crewId !== null && $row['len'] > 0) {
                    $territory[$crewId][$lkId] = (float)$row['len'];
                }
            }
        }

        // Basis-km je Verein (gedeckelt, monatlich), einmalig.
        $monthlyCapKm = $weekCap * $weeksInMonth;
        $cappedKm = [];               // crewId => float km
        foreach ($verified as $crewId => $c) {
            $cappedKm[$crewId] = $this->cappedClubKm($c['memberIds'], $winStart, $winEnd, $monthlyCapKm);
        }

        // Heim-Landkreis + Teilnehmer je Landkreis.
        $homeLk = [];                 // crewId => lkId
        foreach ($territory as $crewId => $byLk) {
            arsort($byLk);
            $homeLk[$crewId] = (int)array_key_first($byLk);
        }
        $participants = [];           // lkId => [crewId,...]
        foreach ($homeLk as $crewId => $lkId) {
            $participants[$lkId][] = $crewId;
        }

        $rows = [];
        $spentCt = 0;
        foreach ($lkIds as $lkId) {
            $clubs = $participants[$lkId] ?? [];
            if ($clubs === []) {
                continue;
            }
            // Nach Territorium im Landkreis sortieren (Champion = Rang 1).
            usort($clubs, static fn($a, $b) => ($territory[$b][$lkId] ?? 0) <=> ($territory[$a][$lkId] ?? 0));

            $bonusSplit = count($clubs) >= $minClubs ? [50, 30, 20] : [];
            foreach ($clubs as $rank => $crewId) {
                $km = $cappedKm[$crewId] ?? 0.0;
                $basisCt = (int)round($km * $rateCt);
                $bonusCt = isset($bonusSplit[$rank]) ? (int)round($potEur * 100 * $bonusSplit[$rank] / 100) : 0;

                // Gesamt-Deckel respektieren.
                if ($budgetCt > 0) {
                    $basisCt = (int)max(0, min($basisCt, $budgetCt - $spentCt));
                    $spentCt += $basisCt;
                    $bonusCt = (int)max(0, min($bonusCt, $budgetCt - $spentCt));
                    $spentCt += $bonusCt;
                }

                $rows[] = [
                    'period' => $period, 'lk' => $lkId, 'crew' => $crewId,
                    'km' => $km, 'basis_ct' => $basisCt,
                    'champion' => $rank === 0 ? 1 : 0, 'bonus_ct' => $bonusCt,
                ];
            }
        }

        $this->storeSnapshot($period, $rows);

        $basisTotal = array_sum(array_column($rows, 'basis_ct'));
        $bonusTotal = array_sum(array_column($rows, 'bonus_ct'));
        return [
            'enabled' => true, 'period' => $period, 'landkreise' => count($lkIds),
            'clubs' => count($rows), 'basis_ct' => (int)$basisTotal, 'bonus_ct' => (int)$bonusTotal,
        ];
    }

    /** @return list<array<string,mixed>> Snapshot einer Periode (für die Admin-Messung). */
    public function report(string $period): array
    {
        $st = $this->pdo->prepare(
            'SELECT s.*, c.name AS crew_name, r.name AS landkreis_name
               FROM supporter_month s
               JOIN game_crew c   ON c.id = s.crew_id
               LEFT JOIN game_region r ON r.id = s.landkreis_region_id
              WHERE s.period = ?
           ORDER BY r.name, s.is_champion DESC, s.basis_ct DESC'
        );
        $st->execute([$period]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- intern ---

    /** @return array<int,array{claimant_id:int,name:string,memberIds:list<int>}> crewId → … */
    private function verifiedCrews(): array
    {
        $crews = [];
        foreach ($this->pdo->query('SELECT id, claimant_id, name FROM game_crew WHERE verified_at IS NOT NULL') as $r) {
            $crews[(int)$r['id']] = ['claimant_id' => (int)$r['claimant_id'], 'name' => (string)$r['name'], 'memberIds' => []];
        }
        if ($crews === []) {
            return [];
        }
        $in = implode(',', array_map('intval', array_keys($crews)));
        foreach ($this->pdo->query("SELECT crew_id, user_id FROM game_crew_member WHERE crew_id IN ($in)") as $r) {
            $crews[(int)$r['crew_id']]['memberIds'][] = (int)$r['user_id'];
        }
        return $crews;
    }

    /** Gedeckelte Club-km (Summe der pro-Mitglied gedeckelten km) im Fenster. */
    private function cappedClubKm(array $memberIds, string $winStart, string $winEnd, float $monthlyCapKm): float
    {
        if ($memberIds === []) {
            return 0.0;
        }
        $in = implode(',', array_map('intval', $memberIds));
        $st = $this->pdo->prepare(
            "SELECT user_id, COALESCE(SUM(distance_m),0)/1000 AS km
               FROM routes
              WHERE deleted_at IS NULL AND user_id IN ($in)
                AND COALESCE(started_at, created_at) >= ? AND COALESCE(started_at, created_at) < ?
           GROUP BY user_id"
        );
        $st->execute([$winStart, $winEnd]);
        $total = 0.0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $total += min((float)$r['km'], $monthlyCapKm);
        }
        return round($total, 2);
    }

    /** @return list<int> */
    private function parseLandkreise(): array
    {
        $raw = trim($this->config->string('supporter_landkreise'));
        if ($raw === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $id = (int)trim($part);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /** @param list<int> $ids @return array<int,string> regionId → path */
    private function regionPaths(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $out = [];
        foreach ($this->pdo->query("SELECT id, path FROM game_region WHERE id IN ($in)") as $r) {
            $out[(int)$r['id']] = (string)$r['path'];
        }
        return $out;
    }

    /** @return array{0:string,1:string,2:float} [start, end, weeksInMonth] */
    private function window(string $period): array
    {
        $tz = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01', $tz);
        if ($start === false) {
            $start = new DateTimeImmutable('first day of this month', $tz);
        }
        $end = $start->modify('first day of next month');
        $days = (int)$start->format('t');
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $days / 7.0];
    }

    /** @param list<array<string,mixed>> $rows */
    private function storeSnapshot(string $period, array $rows): void
    {
        $this->pdo->prepare('DELETE FROM supporter_month WHERE period = ?')->execute([$period]);
        if ($rows === []) {
            return;
        }
        $st = $this->pdo->prepare(
            'INSERT INTO supporter_month
                (period, landkreis_region_id, crew_id, capped_km, basis_ct, is_champion, bonus_ct)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $st->execute([$r['period'], $r['lk'], $r['crew'], $r['km'], $r['basis_ct'], $r['champion'], $r['bonus_ct']]);
        }
    }
}
