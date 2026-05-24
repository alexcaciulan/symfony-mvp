<?php

declare(strict_types=1);

namespace App\Tests\Service\Table;

use App\Service\Table\TableResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableResultTest extends TestCase
{
    #[DataProvider('lastPageCases')]
    public function testLastPage(int $total, int $pageSize, int $expected): void
    {
        $result = new TableResult([], $total, 1, $pageSize);

        self::assertSame($expected, $result->lastPage());
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function lastPageCases(): iterable
    {
        yield 'empty' => [0, 25, 1];
        yield 'exact one page' => [25, 25, 1];
        yield 'one over' => [26, 25, 2];
        yield 'three pages' => [60, 25, 3];
    }
}
