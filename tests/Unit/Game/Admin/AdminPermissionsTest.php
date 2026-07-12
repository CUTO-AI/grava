<?php
declare(strict_types=1);

namespace Tests\Unit\Game\Admin;

use App\Game\Admin\AdminPermissions;
use PHPUnit\Framework\TestCase;

final class AdminPermissionsTest extends TestCase
{
    public function testSuperCanEverything(): void
    {
        $this->assertTrue(AdminPermissions::can('super', 'roles.manage'));
        $this->assertTrue(AdminPermissions::can('super', 'user.ban'));
        $this->assertTrue(AdminPermissions::can('super', 'irgendein.recht'));
    }

    public function testOperator(): void
    {
        $this->assertTrue(AdminPermissions::can('operator', 'user.ban'));
        $this->assertTrue(AdminPermissions::can('operator', 'config.write'));
        $this->assertFalse(AdminPermissions::can('operator', 'roles.manage'));
    }

    public function testSupportIsLimited(): void
    {
        $this->assertTrue(AdminPermissions::can('support', 'user.support'));
        $this->assertTrue(AdminPermissions::can('support', 'user.view'));
        $this->assertFalse(AdminPermissions::can('support', 'user.ban'));
        $this->assertFalse(AdminPermissions::can('support', 'config.write'));
    }

    public function testAnalystIsReadOnly(): void
    {
        $this->assertTrue(AdminPermissions::can('analyst', 'user.view'));
        $this->assertTrue(AdminPermissions::can('analyst', 'audit.view'));
        $this->assertFalse(AdminPermissions::can('analyst', 'ride.reingest'));
        $this->assertFalse(AdminPermissions::can('analyst', 'review.act'));
    }

    public function testUnknownRoleHasNoRights(): void
    {
        $this->assertFalse(AdminPermissions::can('gast', 'user.view'));
        $this->assertFalse(AdminPermissions::isRole('gast'));
        $this->assertTrue(AdminPermissions::isRole('operator'));
    }
}
