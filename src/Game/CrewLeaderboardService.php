<?php
declare(strict_types=1);

namespace App\Game;

use App\Game\Crew\CrewRepository;
use App\Support\Clock;

/**
 * Globale Crew-Rangliste (all-time) nach gehaltener Revierlänge — für die
 * öffentliche Web-Auswertung (WebAnalytics_Concept.md) und optional die App.
 * Rein lesend; die Aggregation liegt in {@see CrewRepository::topByHeldLength()}
 * und ist deckungsgleich mit dem Crew-Weltrang (crewWorldRank). Sortierung:
 * gehaltene Strecke absteigend, Kanten als sekundäre Größe, Tie stabil per
 * crew_id. Eine spätere Kilometer-Rangliste kommt als eigene Metrik.
 */
final class CrewLeaderboardService
{
    private const TOP_N = 100;

    public function __construct(private readonly CrewRepository $crews) {}

    /**
     * @return array{entries:list<array{
     *   rank:int,slug:string,name:string,member_count:int,logo_updated_at:?string,
     *   faction:?array{key:string,color:string},held_length_m:float,held_edges:int
     * }>}
     */
    public function leaderboard(int $limit = self::TOP_N): array
    {
        $limit = max(1, min($limit, self::TOP_N));
        $entries = [];
        $rank = 0;
        foreach ($this->crews->topByHeldLength($limit) as $r) {
            $rank++;
            $faction = null;
            if ($r['faction_key'] !== null && $r['faction_color'] !== null) {
                $faction = ['key' => $r['faction_key'], 'color' => $r['faction_color']];
            }
            $entries[] = [
                'rank'            => $rank,
                'slug'            => $r['slug'],
                'name'            => $r['name'],
                'member_count'    => $r['member_count'],
                'logo_updated_at' => $r['logo_updated_at'] !== null
                    ? Clock::toIso8601(substr($r['logo_updated_at'], 0, 19))
                    : null,
                'faction'         => $faction,
                'held_length_m'   => round($r['held_length_m'], 2),
                'held_edges'      => $r['held_edges'],
            ];
        }
        return ['entries' => $entries];
    }
}
