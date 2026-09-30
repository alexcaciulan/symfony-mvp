<?php

declare(strict_types=1);

namespace App\Tests\Service\Party;

use App\Service\Party\CuiNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CuiNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function spellings(): iterable
    {
        yield 'plain' => ['123', '123'];
        yield 'RO prefix' => ['RO123', '123'];
        yield 'lowercase with spaces' => [' ro 123 ', '123'];
        yield 'leading zero' => ['0123', '123'];
        yield 'RO and leading zero' => ['RO0123', '123'];
        yield 'non-breaking space and tab' => ["RO\u{00A0}12\t3", '123'];
        yield 'RO alone' => ['RO', null];
        yield 'zero' => ['0', null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
    }

    #[DataProvider('spellings')]
    public function testCanonicalFormMakesSpellingsOfOneCompanyEqual(?string $input, ?string $expected): void
    {
        self::assertSame($expected, CuiNormalizer::canonical($input));
    }
}
