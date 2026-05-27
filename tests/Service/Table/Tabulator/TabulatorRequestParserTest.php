<?php

declare(strict_types=1);

namespace App\Tests\Service\Table\Tabulator;

use App\Service\Table\Tabulator\TabulatorRequestParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TabulatorRequestParserTest extends TestCase
{
    public function testParsesPageSizeSortAndFilter(): void
    {
        $request = Request::create('/api/table/notifications', 'GET', [
            'page' => '2',
            'size' => '50',
            'sort' => [['field' => 'createdAt', 'dir' => 'desc']],
            'filter' => [['field' => 'type', 'value' => 'portal_update']],
        ]);

        $query = (new TabulatorRequestParser())->parse($request);

        self::assertSame(2, $query->page);
        self::assertSame(50, $query->pageSize);
        self::assertSame('createdAt', $query->sortField);
        self::assertSame('DESC', $query->sortDir);
        self::assertSame(['type' => 'portal_update'], $query->filters);
    }

    public function testDefaultsWhenNoParams(): void
    {
        $query = (new TabulatorRequestParser())->parse(Request::create('/api/table/notifications'));

        self::assertSame(1, $query->page);
        self::assertSame(25, $query->pageSize);
        self::assertNull($query->sortField);
        self::assertSame('ASC', $query->sortDir);
        self::assertSame([], $query->filters);
    }

    public function testPageIsClampedToAtLeastOne(): void
    {
        $query = (new TabulatorRequestParser())->parse(
            Request::create('/api/table/notifications', 'GET', ['page' => '0']),
        );

        self::assertSame(1, $query->page);
    }

    public function testParsesMultiValueFilter(): void
    {
        $request = Request::create('/api/table/cases', 'GET', [
            'filter' => [['field' => 'status', 'value' => ['AMIABIL', 'RESPINSA']]],
        ]);

        $query = (new TabulatorRequestParser())->parse($request);

        self::assertSame(['status' => ['AMIABIL', 'RESPINSA']], $query->filters);
    }
}
