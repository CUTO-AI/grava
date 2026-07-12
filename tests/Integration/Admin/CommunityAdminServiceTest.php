<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Game\Admin\CommunityAdminService;
use App\Game\Crew\CrewRepository;
use App\Game\GameConfig;
use App\Game\RegionOwnershipService;
use App\Game\RegionRepository;
use Tests\IntegrationTestCase;

/**
 * Community-Konsolidierung (GameAdmin_Concept.md Phase 2): Crew-Detail, Umbenennen
 * (mit Validierung) und Auflösen.
 */
final class CommunityAdminServiceTest extends IntegrationTestCase
{
    private function seedCrew(string $name, int $ownerId): int
    {
        $this->pdo->prepare("INSERT INTO game_claimant (type, user_id) VALUES ('group', NULL)")->execute();
        $claimant = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code) VALUES (?, ?, ?, ?, ?)'
        )->execute([$claimant, $name, strtolower($name) . bin2hex(random_bytes(2)), $ownerId, strtoupper(bin2hex(random_bytes(4)))]);
        $crewId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO game_crew_member (user_id, crew_id, role) VALUES (?, ?, 'captain')")
            ->execute([$ownerId, $crewId]);
        return $crewId;
    }

    private function svc(): CommunityAdminService
    {
        return new CommunityAdminService(
            $this->pdo,
            new CrewRepository($this->pdo),
            new RegionOwnershipService(new RegionRepository($this->pdo), new GameConfig($this->pdo)),
        );
    }

    public function testCrewDetailAndRename(): void
    {
        $owner = $this->createUser('cap', 'cap@t.local');
        $crewId = $this->seedCrew('Alpha', $owner);
        $svc = $this->svc();

        $detail = $svc->crewDetail($crewId);
        $this->assertNotNull($detail);
        $this->assertSame(1, $detail['memberCount']);
        $this->assertSame('Alpha', $detail['crew']['name']);

        $this->assertTrue($svc->renameCrew($crewId, 'Beta'));
        $this->assertSame('Beta', $svc->crewDetail($crewId)['crew']['name']);

        $this->assertFalse($svc->renameCrew($crewId, ''));
        $this->assertFalse($svc->renameCrew($crewId, str_repeat('x', 41)));
        $this->assertSame('Beta', $svc->crewDetail($crewId)['crew']['name']);   // unverändert
    }

    public function testDissolve(): void
    {
        $owner = $this->createUser('cap2', 'cap2@t.local');
        $crewId = $this->seedCrew('Gamma', $owner);
        $svc = $this->svc();

        $this->assertTrue($svc->dissolveCrew($crewId));
        $this->assertNull($svc->crewDetail($crewId));
        $members = (int)$this->pdo->query("SELECT COUNT(*) FROM game_crew_member WHERE crew_id = {$crewId}")->fetchColumn();
        $this->assertSame(0, $members);
    }
}
