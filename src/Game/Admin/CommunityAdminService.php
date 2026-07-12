<?php
declare(strict_types=1);

namespace App\Game\Admin;

use App\Game\Crew\CrewRepository;
use App\Game\Faction\FactionService;
use App\Game\RegionOwnershipService;
use App\Game\RegionService;
use App\Support\Clock;
use PDO;

/**
 * Konsolidierte Community-Verwaltung (GameAdmin_Concept.md Phase 2): Crews,
 * Fraktionen und Gebiete an einer Stelle. Bündelt die vorhandenen Dienste
 * (crewLeaderboard, FactionService::standings, RegionService::adminRegionOverview,
 * RegionOwnershipService) + Crew-Moderationsaktionen (umbenennen, auflösen).
 */
final class CommunityAdminService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CrewRepository $crews,
        private readonly RegionOwnershipService $ownership,
        // Reine Lese-Aggregation — nullable, damit die Moderationsaktionen ohne
        // den schweren Dependency-Graph (Faction/Region) testbar bleiben.
        private readonly ?GameAdminService $game = null,
        private readonly ?FactionService $factions = null,
        private readonly ?RegionService $regions = null,
    ) {}

    /** @return list<array<string,mixed>> */
    public function crewList(int $limit = 100): array
    {
        return $this->game?->crewLeaderboard($limit) ?? [];
    }

    /** @return array{crew:array<string,mixed>,members:list<array<string,mixed>>,memberCount:int}|null */
    public function crewDetail(int $crewId): ?array
    {
        $crew = $this->crews->crewById($crewId);
        if ($crew === null) {
            return null;
        }
        return [
            'crew' => $crew,
            'members' => $this->crews->members($crewId),
            'memberCount' => $this->crews->memberCount($crewId),
        ];
    }

    /** Benennt eine Crew um (1..40 Zeichen). Slug bleibt stabil. */
    public function renameCrew(int $crewId, string $name): bool
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) {
            return false;
        }
        $this->pdo->prepare('UPDATE game_crew SET name = ? WHERE id = ?')->execute([$name, $crewId]);
        return true;
    }

    public function clearCrewLogo(int $crewId): void
    {
        $this->crews->clearLogo($crewId);
    }

    /**
     * Löst eine Crew auf: entfernt alle Mitglieder, löscht die Crew und ihren
     * Gruppen-Claimant. Achtung: der Claimant-Delete kaskadiert die Crew-Pässe
     * (das Crew-Territorium wird freigegeben). Danach Besitz neu rechnen.
     */
    public function dissolveCrew(int $crewId): bool
    {
        $crew = $this->crews->crewById($crewId);
        if ($crew === null) {
            return false;
        }
        foreach ($this->crews->members($crewId) as $m) {
            $this->crews->removeMember((int)$m['user_id']);
        }
        $this->crews->deleteCrew($crewId);
        $this->crews->deleteClaimant((int)$crew['claimant_id']);
        // Territorium wurde freigegeben → Besitz-Cache aktualisieren (best-effort).
        try {
            $this->ownership->recomputeAll(Clock::nowUtcString());
        } catch (\Throwable $e) {
            error_log('region recompute nach crew-dissolve fehlgeschlagen: ' . $e->getMessage());
        }
        return true;
    }

    /** @return array<string,mixed> Fraktions-Standings/Balance (['factions'=>...]). */
    public function factionStandings(): array
    {
        return $this->factions?->standings() ?? ['factions' => []];
    }

    /** @return array<string,mixed> Gebiets-Übersicht (summary/owned/topOwners). */
    public function regionsOverview(): array
    {
        return $this->regions?->adminRegionOverview() ?? [];
    }

    /** Rechnet den Gebiets-Besitz neu und liefert die Anzahl geänderter Gebiete. */
    public function recomputeRegions(): int
    {
        $res = $this->ownership->recomputeAll(Clock::nowUtcString());
        return count($res['changes'] ?? []);
    }
}
