<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Game\Admin\GameAuditService;
use Tests\IntegrationTestCase;

/**
 * Durchsuchbare Audit-Sicht (GameAdmin_Concept.md, Phase 0): Filter nach
 * Admin-E-Mail, Aktion und Zeitraum + dekodiertes detail_json.
 */
final class GameAuditSearchTest extends IntegrationTestCase
{
    public function testSearchFiltersAndDecodesDetail(): void
    {
        $uid = $this->createUser(null, 'ops@test.local');
        $audit = new GameAuditService($this->pdo);
        $audit->record($uid, 'user_ban', 'user#7', ['reason' => 'cheating']);
        $audit->record($uid, 'role_assign', 'someone@test.local', ['role' => 'operator']);

        $this->assertCount(2, $audit->search());

        $ban = $audit->search(null, 'ban');
        $this->assertCount(1, $ban);
        $this->assertSame('user_ban', $ban[0]['action']);
        $this->assertSame('cheating', $ban[0]['detail']['reason']);
        $this->assertSame('ops@test.local', $ban[0]['admin_email']);

        $this->assertCount(2, $audit->search('ops@'));
        $this->assertCount(0, $audit->search('niemand@'));

        // Zeitraum-Filter: alles nach einem Zukunftsdatum → leer.
        $this->assertCount(0, $audit->search(null, null, '2999-01-01 00:00:00'));
    }

    public function testSearchPaginates(): void
    {
        $uid = $this->createUser(null, 'ops2@test.local');
        $audit = new GameAuditService($this->pdo);
        for ($i = 0; $i < 5; $i++) {
            $audit->record($uid, 'noop', 't' . $i);
        }
        $this->assertCount(2, $audit->search(null, null, null, 2, 0));
        $this->assertCount(2, $audit->search(null, null, null, 2, 2));
        $this->assertCount(1, $audit->search(null, null, null, 2, 4));
    }
}
