<?php

declare(strict_types=1);

namespace App\Tests\Service\Table\Tabulator;

use App\Service\Table\Tabulator\TabulatorResponseFactory;
use App\Service\Table\TableResult;
use PHPUnit\Framework\TestCase;

final class TabulatorResponseFactoryTest extends TestCase
{
    public function testShapesResultIntoTabulatorEnvelope(): void
    {
        $result = new TableResult([['id' => 1], ['id' => 2]], total: 60, page: 1, pageSize: 25);

        $out = (new TabulatorResponseFactory())->format($result);

        self::assertSame(3, $out['last_page']);
        self::assertSame(60, $out['last_row']);
        self::assertSame([['id' => 1], ['id' => 2]], $out['data']);
    }
}
