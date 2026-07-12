<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Game\Admin\BroadcastService;
use App\Push\ApnsTransport;
use Tests\IntegrationTestCase;

/**
 * Broadcast-Push (GameAdmin_Concept.md Phase 2): Segment-Schätzung, Einreihen und
 * Versand durch den Worker (gebannte/inaktive Nutzer ausgeschlossen).
 */
final class BroadcastServiceTest extends IntegrationTestCase
{
    private function addDevice(int $userId, string $token): void
    {
        $this->pdo->prepare(
            'INSERT INTO push_devices (user_id, token, platform, environment) VALUES (?, ?, "ios", "production")'
        )->execute([$userId, $token]);
    }

    private function fakeTransport(): ApnsTransport
    {
        return new class implements ApnsTransport {
            /** @var list<string> */
            public array $sent = [];
            public function send(string $environment, string $deviceToken, array $payload, ?string $collapseId = null): int
            {
                $this->sent[] = $deviceToken;
                return 200;
            }
        };
    }

    public function testEstimateExcludesBannedAndInactive(): void
    {
        $ok1 = $this->createUser(null, 'ok1@t.local');
        $ok2 = $this->createUser(null, 'ok2@t.local');
        $banned = $this->createUser(null, 'ban@t.local');
        $inactive = $this->createUser(null, 'off@t.local');
        $this->addDevice($ok1, 't1');
        $this->addDevice($ok2, 't2');
        $this->addDevice($banned, 't3');
        $this->addDevice($inactive, 't4');
        $this->pdo->prepare('INSERT INTO game_user_flag (user_id, banned) VALUES (?, 1)')->execute([$banned]);
        $this->pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = ?")->execute([$inactive]);

        $svc = new BroadcastService($this->pdo, $this->fakeTransport());
        $this->assertSame(2, $svc->estimate('all'));
    }

    public function testQueueThenRunSends(): void
    {
        $u1 = $this->createUser(null, 'a@t.local');
        $u2 = $this->createUser(null, 'b@t.local');
        $this->addDevice($u1, 'tok1');
        $this->addDevice($u2, 'tok2');

        $transport = $this->fakeTransport();
        $svc = new BroadcastService($this->pdo, $transport);

        $id = $svc->queue(1, 'Titel', 'Hallo Welt', 'cyberride://home', 'all');
        $this->assertGreaterThan(0, $id);
        $list = $svc->list(10);
        $this->assertSame('queued', $list[0]['status']);
        $this->assertSame(2, (int)$list[0]['recipients']);

        $res = $svc->runNext();
        $this->assertSame($id, $res['id']);
        $this->assertSame(2, $res['sent']);
        $this->assertCount(2, $transport->sent);

        $this->assertNull($svc->runNext());   // Queue leer

        $after = $svc->list(10);
        $this->assertSame('sent', $after[0]['status']);
        $this->assertSame(2, (int)$after[0]['sent']);
    }
}
