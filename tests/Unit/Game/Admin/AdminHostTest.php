<?php
declare(strict_types=1);

namespace Tests\Unit\Game\Admin;

use App\Game\Admin\AdminHost;
use PHPUnit\Framework\TestCase;

final class AdminHostTest extends TestCase
{
    public function testExplicitAdminHost(): void
    {
        $this->assertTrue(AdminHost::isAdmin('admin.grava.world', 'admin.grava.world', 'https://grava.world'));
        $this->assertFalse(AdminHost::isAdmin('grava.world', 'admin.grava.world', 'https://grava.world'));
    }

    public function testDerivedFromAppUrlWhenConfiguredHostEmpty(): void
    {
        $this->assertTrue(AdminHost::isAdmin('admin.grava.world', '', 'https://grava.world'));
    }

    public function testCaseInsensitiveAndPortStripped(): void
    {
        $this->assertTrue(AdminHost::isAdmin('Admin.Grava.World:443', 'admin.grava.world', ''));
    }

    public function testDerivedFromPublicWebUrlEvenIfConfiguredHostIsStale(): void
    {
        // Prod-Fall: ADMIN_HOST zeigt noch auf den toten grava-Host, aber der
        // Admin muss über admin.<PUBLIC_WEB_URL> (cyberride) erreichbar sein.
        $this->assertTrue(AdminHost::isAdmin(
            'admin.cyberride.world', 'admin.grava.world',
            'https://grava.world', 'https://cyberride.world',
        ));
        // Der alte Host bleibt zugleich gültig (Union → Umzug bricht nichts).
        $this->assertTrue(AdminHost::isAdmin(
            'admin.grava.world', 'admin.grava.world',
            'https://grava.world', 'https://cyberride.world',
        ));
    }

    public function testCommaSeparatedList(): void
    {
        $cfg = 'admin.grava.world, admin.cyberride.world';
        $this->assertTrue(AdminHost::isAdmin('admin.cyberride.world', $cfg, '', ''));
        $this->assertTrue(AdminHost::isAdmin('admin.grava.world', $cfg, '', ''));
        $this->assertFalse(AdminHost::isAdmin('cyberride.world', $cfg, '', ''));
    }
}
