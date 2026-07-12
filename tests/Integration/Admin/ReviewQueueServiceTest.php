<?php
declare(strict_types=1);

namespace Tests\Integration\Admin;

use App\Game\Admin\ReviewQueueService;
use Tests\IntegrationTestCase;

/**
 * Review-Queue (GameAdmin_Concept.md Modul D): offene Meldungen + Statuswechsel.
 */
final class ReviewQueueServiceTest extends IntegrationTestCase
{
    private function seedReport(int $reporterId, string $type, int $contentId, string $reason): void
    {
        $this->pdo->prepare(
            'INSERT INTO content_report (reporter_id, content_type, content_id, reason, description)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$reporterId, $type, $contentId, $reason, 'Testmeldung']);
    }

    public function testOpenReportsAndStatusChange(): void
    {
        $reporter = $this->createUser(null, 'reporter@test.local');
        $target = $this->createUser(null, 'target@test.local');
        $svc = new ReviewQueueService($this->pdo);

        $this->seedReport($reporter, 'user', $target, 'abuse');
        $this->seedReport($reporter, 'route', 999, 'spam');

        $this->assertSame(2, $svc->openReportCount());
        $open = $svc->openReports(50, 0);
        $this->assertCount(2, $open);
        $this->assertSame('reporter@test.local', $open[0]['reporter_email']);

        $reportId = (int)$open[0]['id'];
        $this->assertTrue($svc->setReportStatus($reportId, 'resolved', $target));
        $this->assertSame(1, $svc->openReportCount());

        $row = $this->pdo->query("SELECT status, reviewed_at, reviewed_by FROM content_report WHERE id = {$reportId}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('resolved', $row['status']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertSame($target, (int)$row['reviewed_by']);

        $this->assertFalse($svc->setReportStatus($reportId, 'bogus', $target));
    }
}
