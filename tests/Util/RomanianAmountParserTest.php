<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\RomanianAmountParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parsed amount becomes the principal filed with the court, so the parser
 * must read every Romanian form the lawyer types, reject anything ambiguous
 * rather than guess, and never turn a mistake into a plausible-looking number.
 */
final class RomanianAmountParserTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testAcceptedFormats(string $input, float $expected): void
    {
        self::assertSame($expected, RomanianAmountParser::parse($input));
    }

    /** @return iterable<string, array{string, float}> */
    public static function accepted(): iterable
    {
        yield 'plain integer' => ['1234', 1234.0];
        yield 'dot decimal' => ['493.23', 493.23];
        yield 'comma decimal' => ['600,50', 600.50];
        yield 'romanian grouped with comma decimal' => ['1.234,56', 1234.56];
        yield 'romanian grouped integer' => ['1.234', 1234.0];
        yield 'romanian grouped millions' => ['1.234.567', 1234567.0];
        yield 'single dot one decimal' => ['1234.5', 1234.5];
        yield 'spaces are ignored' => [' 1 234,56 ', 1234.56];
    }

    #[DataProvider('rejected')]
    public function testRejectedInput(string $input): void
    {
        self::assertNull(RomanianAmountParser::parse($input));
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'letters' => ['abc'];
        yield 'currency suffix' => ['100 RON'];
        yield 'scientific notation' => ['1e6'];
        yield 'negative' => ['-100'];
        yield 'zero' => ['0'];
        // The one the review caught: US notation must not become 1.23.
        yield 'american notation is rejected not guessed' => ['1,234.56'];
        yield 'two commas' => ['1,23,45'];
        yield 'four digits then three decimals' => ['1234.567'];
        yield 'too many decimals' => ['12.3456'];
    }
}
