<?php
declare(strict_types=1);

namespace App\Game;

/**
 * Berechnet den täglichen Aktivitäts-Cache (game_region_activity) für die
 * Nordstern-Metrik (UserGrowth_Concept.md §4): distinct aktive Fahrer (WAR) je
 * Gebiet und Zeitfenster (7/30 Tage), inkl. Solo/Crew-Aufschlüsselung.
 *
 * WAR ist — anders als gehaltene Länge/Kanten — NICHT additiv über die Hierarchie
 * (derselbe Fahrer in mehreren Gemeinden zählt im Landkreis nur einmal). Deshalb
 * wird pro Blatt-Gebiet die distinct Fahrer-/Crew-Menge geholt und entlang des
 * materialisierten `path` auf alle Ahnen VEREINIGT (Set-Union), analog zum
 * Bottom-up-Rollup in {@see RegionOwnershipService}. Nur Kanten sind additiv.
 *
 * Voller Recompute ist günstig: nur Gebiete MIT Aktivität im Fenster (plus Ahnen)
 * werden berührt. Befüllt vom Cron `game:region-activity-refresh`.
 */
final class RegionActivityCacheService
{
    /** Zeitfenster (Tage), die gecacht werden — deckungsgleich mit der API/UI. */
    private const WINDOWS = [7, 30];

    public function __construct(
        private readonly RegionRepository $repo,
    ) {}

    /**
     * @return array{windows:int,regions:int}
     */
    public function recomputeAll(): array
    {
        $regionsTouched = 0;

        foreach (self::WINDOWS as $win) {
            $since = date('Y-m-d', time() - ($win * 86400));

            $userRows = $this->repo->activityLeafUserRows($since);
            $crewRows = $this->repo->activityLeafCrewRows($since);
            $edgeRows = $this->repo->activityLeafEdgeCounts($since);

            // Ahnenpfade aller beteiligten Blatt-Gebiete einmal laden.
            $leafIds = [];
            foreach ($userRows as $r) { $leafIds[$r['leaf']] = true; }
            foreach ($crewRows as $r) { $leafIds[$r['leaf']] = true; }
            foreach ($edgeRows as $r) { $leafIds[$r['leaf']] = true; }
            $meta = $this->repo->metaForRegions(array_keys($leafIds));

            /** @var array<int,list<int>> $ancCache Blatt → Ahnen-IDs (inkl. Selbst) */
            $ancCache = [];
            $ancestorsOf = static function (int $leaf) use (&$ancCache, $meta): array {
                if (isset($ancCache[$leaf])) {
                    return $ancCache[$leaf];
                }
                $path = $meta[$leaf]['path'] ?? null;
                $ids = $path === null
                    ? []
                    : array_map('intval', array_values(array_filter(
                        explode('/', trim($path, '/')),
                        static fn(string $s): bool => $s !== ''
                    )));
                return $ancCache[$leaf] = $ids;
            };

            /** @var array<int,array<int,true>> $users regionId → Set userId */
            $users = [];
            /** @var array<int,array<int,true>> $solo */
            $solo = [];
            /** @var array<int,array<int,true>> $crewRiders */
            $crewRiders = [];
            /** @var array<int,array<int,true>> $crews regionId → Set crew-claimantId */
            $crews = [];
            /** @var array<int,int> $edges regionId → Kantenzahl (additiv) */
            $edges = [];

            foreach ($userRows as $r) {
                $uid = $r['uid'];
                $type = $r['ctype'];
                foreach ($ancestorsOf($r['leaf']) as $rid) {
                    $users[$rid][$uid] = true;
                    if ($type === 'rider') {
                        $solo[$rid][$uid] = true;
                    } elseif ($type === 'group') {
                        $crewRiders[$rid][$uid] = true;
                    }
                }
            }
            foreach ($crewRows as $r) {
                $cid = $r['cid'];
                foreach ($ancestorsOf($r['leaf']) as $rid) {
                    $crews[$rid][$cid] = true;
                }
            }
            foreach ($edgeRows as $r) {
                $ec = $r['edges'];
                foreach ($ancestorsOf($r['leaf']) as $rid) {
                    $edges[$rid] = ($edges[$rid] ?? 0) + $ec;
                }
            }

            // Jeder Pass erzeugt eine user-Zeile → $users deckt alle berührten Gebiete ab.
            $out = [];
            foreach ($users as $rid => $set) {
                $out[$rid] = [
                    'war'         => count($set),
                    'solo'        => count($solo[$rid] ?? []),
                    'crew_riders' => count($crewRiders[$rid] ?? []),
                    'crew_count'  => count($crews[$rid] ?? []),
                    'edges'       => $edges[$rid] ?? 0,
                ];
            }

            $this->repo->replaceActivityCache($win, $out);
            $regionsTouched += count($out);
        }

        return ['windows' => count(self::WINDOWS), 'regions' => $regionsTouched];
    }
}
