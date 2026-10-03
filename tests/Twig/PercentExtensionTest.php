<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\PercentExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PercentExtensionTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function percentages(): iterable
    {
        yield 'whole number' => ['10.00', '10'];
        yield 'one decimal kept, not rounded up' => ['7.50', '7,5'];
        yield 'two decimals' => [0.15, '0,15'];
        yield 'zero' => ['0', '0'];
        yield 'hundred' => [100.0, '100'];
    }

    #[DataProvider('percentages')]
    public function testPercentIsWrittenWithoutTrailingZeros(mixed $value, string $expected): void
    {
        self::assertSame($expected, PercentExtension::format($value));
    }
}
