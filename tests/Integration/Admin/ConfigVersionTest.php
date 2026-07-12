<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Game\Admin\GameAuditService;
use App\Game\Admin\GameConfigAdminService;
use App\Game\Admin\GameConfigVersionService;
use App\Game\GameConfig;
use Tests\IntegrationTestCase;

/**
 * Config-Versionierung (GameAdmin_Concept.md Phase 2): Snapshot bei Änderung,
 * Diff zur Vorversion, und dass der resultierende Zustand (trotz GameConfig-Cache)
 * korrekt gespeichert wird.
 */
final class ConfigVersionTest extends IntegrationTestCase
{
    public function testRecordAndDiff(): void
    {
        $svc = new GameConfigVersionService($this->pdo);
        $v1 = $svc->record(1, ['a' => '1', 'b' => '2'], 'erste');
        $v2 = $svc->record(1, ['a' => '1', 'b' => '3', 'c' => '4'], 'zweite');

        $this->assertGreaterThan($v1, $v2);
        $this->assertCount(2, $svc->listVersions(50));
        $this->assertSame('3', $svc->get($v2)['values']['b']);

        $diff = $svc->diffToPrevious($v2);
        $this->assertArrayHasKey('b', $diff);
        $this->assertSame(['before' => '2', 'after' => '3'], $diff['b']);
        $this->assertArrayHasKey('c', $diff);
        $this->assertArrayNotHasKey('a', $diff);   // unverändert
    }

    public function testUpdateCreatesSnapshotWithResultingState(): void
    {
        $config = new GameConfig($this->pdo);
        $versions = new GameConfigVersionService($this->pdo);
        $admin = new GameConfigAdminService($this->pdo, $config, new GameAuditService($this->pdo), $versions);

        $errors = $admin->update(1, ['mod_max_passes_per_day' => '250']);
        $this->assertSame([], $errors);

        $list = $versions->listVersions(10);
        $this->assertCount(1, $list);
        // Snapshot muss den NEUEN Wert enthalten (Cache-Merge korrekt).
        $snap = $versions->get($list[0]['id'])['values'];
        $this->assertSame('250', $snap['mod_max_passes_per_day']);

        // Kein Change → keine neue Version. Frisches Config/Admin = neuer Request
        // (GameConfig cached pro Request; direkt aus der DB gelesen ist 250).
        $admin2 = new GameConfigAdminService($this->pdo, new GameConfig($this->pdo), new GameAuditService($this->pdo), $versions);
        $admin2->update(1, ['mod_max_passes_per_day' => '250']);
        $this->assertCount(1, $versions->listVersions(10));
    }
}
