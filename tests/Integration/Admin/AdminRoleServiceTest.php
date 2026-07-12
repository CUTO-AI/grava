<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Game\Admin\AdminGuard;
use App\Game\Admin\AdminRoleService;
use Tests\IntegrationTestCase;

/**
 * RBAC-Rollenauflösung (GameAdmin_Concept.md, Phase 0): super kommt aus
 * ADMIN_EMAILS, alle anderen Rollen aus admin_roles; setRole/removeRole/list.
 */
final class AdminRoleServiceTest extends IntegrationTestCase
{
    public function testSuperComesFromAdminEmails(): void
    {
        $email = 'boss@test.local';
        $uid = $this->createUser(null, $email);
        $svc = new AdminRoleService($this->pdo, new AdminGuard($email));
        $this->assertSame('super', $svc->roleFor($uid, $email));
        $this->assertTrue($svc->can($uid, $email, 'roles.manage'));
    }

    public function testAssignedRoleAndPermissions(): void
    {
        $email = 'ops@test.local';
        $uid = $this->createUser(null, $email);
        $svc = new AdminRoleService($this->pdo, new AdminGuard(''));   // niemand ist super

        $this->assertNull($svc->roleFor($uid, $email));

        $this->assertTrue($svc->setRole($uid, 'operator'));
        $this->assertSame('operator', $svc->roleFor($uid, $email));
        $this->assertTrue($svc->can($uid, $email, 'user.ban'));
        $this->assertFalse($svc->can($uid, $email, 'roles.manage'));

        // super/ungültig werden nicht über die DB vergeben.
        $this->assertFalse($svc->setRole($uid, 'super'));
        $this->assertFalse($svc->setRole($uid, 'bogus'));
        $this->assertSame('operator', $svc->roleFor($uid, $email));   // unverändert

        $list = $svc->list();
        $this->assertCount(1, $list);
        $this->assertSame('operator', $list[0]['role']);
        $this->assertSame($email, $list[0]['email']);

        $svc->removeRole($uid);
        $this->assertNull($svc->roleFor($uid, $email));
    }

    public function testFindUserByEmailOrHandle(): void
    {
        $uid = $this->createUser('rider42', 'rider42@test.local');
        $svc = new AdminRoleService($this->pdo, new AdminGuard(''));
        $this->assertSame($uid, (int)$svc->findUser('rider42@test.local')['id']);
        $this->assertSame($uid, (int)$svc->findUser('rider42')['id']);
        $this->assertNull($svc->findUser('gibtsnicht'));
    }
}
