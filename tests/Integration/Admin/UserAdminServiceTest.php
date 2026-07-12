<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Game\Admin\GameAdminService;
use App\Game\GameConfig;
use App\Game\GameRepository;
use App\Game\Admin\UserAdminService;
use Tests\IntegrationTestCase;

/**
 * Nutzerverwaltung (GameAdmin_Concept.md Modul B): Suche, Detail und Support-
 * Aktionen (Verify erzwingen, Profil ändern inkl. Handle-Kollision, Anonymisieren).
 */
final class UserAdminServiceTest extends IntegrationTestCase
{
    private function svc(): UserAdminService
    {
        $game = new GameAdminService($this->pdo, new GameRepository($this->pdo), new GameConfig($this->pdo));
        return new UserAdminService($this->pdo, $game);
    }

    public function testSearchByEmailHandleId(): void
    {
        $uid = $this->createUser('speedy', 'speedy@test.local');
        $svc = $this->svc();

        $this->assertSame($uid, (int)$svc->search('speedy@test', 'created_at', 'desc', 50, 0)[0]['id']);
        $this->assertSame($uid, (int)$svc->search('speedy', 'created_at', 'desc', 50, 0)[0]['id']);
        $this->assertSame($uid, (int)$svc->search((string)$uid, 'created_at', 'desc', 50, 0)[0]['id']);
        $this->assertSame([], $svc->search('gibtsnicht', 'created_at', 'desc', 50, 0));
    }

    public function testForceVerify(): void
    {
        $uid = $this->createUser(null, 'v@test.local');
        // Frisch angelegte Test-User sind bereits verifiziert (email_verified_at gesetzt);
        // deshalb erst zurücksetzen, dann erzwingen.
        $this->pdo->prepare('UPDATE users SET email_verified_at = NULL WHERE id = ?')->execute([$uid]);
        $this->svc()->forceVerify($uid);
        $verified = $this->pdo->query("SELECT email_verified_at FROM users WHERE id = {$uid}")->fetchColumn();
        $this->assertNotNull($verified);
    }

    public function testSetProfileAndHandleCollision(): void
    {
        $a = $this->createUser('alpha', 'a@test.local');
        $b = $this->createUser('beta', 'b@test.local');
        $svc = $this->svc();

        $this->assertTrue($svc->setProfile($a, 'Alpha Rider', 'alpha2'));
        $row = $this->pdo->query("SELECT display_name, public_handle FROM users WHERE id = {$a}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('Alpha Rider', $row['display_name']);
        $this->assertSame('alpha2', $row['public_handle']);

        // b darf sich nicht das Handle von beta→alpha2 krallen, wenn a es hält.
        $this->assertFalse($svc->setProfile($b, 'Beta', 'alpha2'));
    }

    public function testAnonymize(): void
    {
        $uid = $this->createUser('gone', 'gone@test.local');
        $this->svc()->anonymize($uid);
        $row = $this->pdo->query("SELECT status, email, public_handle, deleted_at FROM users WHERE id = {$uid}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('deleted', $row['status']);
        $this->assertStringContainsString('anon.invalid', $row['email']);
        $this->assertNull($row['public_handle']);
        $this->assertNotNull($row['deleted_at']);
    }
}
