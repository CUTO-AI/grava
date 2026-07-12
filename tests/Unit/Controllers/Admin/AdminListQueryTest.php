<?php
declare(strict_types=1);

namespace Tests\Unit\Controllers\Admin;

use App\Controllers\Web\Admin\AdminListQuery;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class AdminListQueryTest extends TestCase
{
    private function req(array $query): Request
    {
        return new Request('GET', '/admin/x', '', '127.0.0.1', 'test', query: $query);
    }

    public function testDefaults(): void
    {
        $q = AdminListQuery::fromRequest($this->req([]), ['created_at', 'email'], 'created_at');
        $this->assertSame('', $q->q);
        $this->assertSame(1, $q->page);
        $this->assertSame(50, $q->perPage);
        $this->assertSame('created_at', $q->sort);
        $this->assertSame('desc', $q->dir);
        $this->assertSame(0, $q->offset);
    }

    public function testSortWhitelistRejectsUnknown(): void
    {
        $q = AdminListQuery::fromRequest(
            $this->req(['sort' => 'password', 'dir' => 'asc']),
            ['created_at', 'email'], 'created_at',
        );
        $this->assertSame('created_at', $q->sort);   // unbekannt → Default
        $this->assertSame('asc', $q->dir);
        $this->assertSame('ORDER BY created_at asc', $q->orderBy());
    }

    public function testPaginationOffsetAndClamp(): void
    {
        $q = AdminListQuery::fromRequest(
            $this->req(['page' => '3', 'per_page' => '20']),
            ['created_at'], 'created_at',
        );
        $this->assertSame(3, $q->page);
        $this->assertSame(20, $q->perPage);
        $this->assertSame(40, $q->offset);

        $clamped = AdminListQuery::fromRequest(
            $this->req(['per_page' => '99999']),
            ['created_at'], 'created_at', 50, 200,
        );
        $this->assertSame(200, $clamped->perPage);
    }

    public function testWithParamsPreservesAndOverrides(): void
    {
        $q = AdminListQuery::fromRequest($this->req(['q' => 'anna', 'page' => '2']), ['created_at'], 'created_at');
        parse_str($q->withParams(['page' => 5]), $out);
        $this->assertSame('anna', $out['q']);
        $this->assertSame('5', $out['page']);
    }
}
