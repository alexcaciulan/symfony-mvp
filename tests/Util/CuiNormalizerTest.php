<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\CuiNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CuiNormalizerTest extends TestCase
{
    #[DataProvider('spellings')]
    public function testEverySpellingOfOneCodeNormalizesToTheSameValue(string $raw): void
    {
        self::assertSame('12345678', CuiNormalizer::normalize($raw));
    }

    /** @return iterable<string, array{string}> */
    public static function spellings(): iterable
    {
        yield 'plain' => ['12345678'];
        yield 'RO prefix' => ['RO12345678'];
        yield 'lowercase prefix' => ['ro12345678'];
        yield 'spaces' => ['RO 12 345 678'];
        yield 'leading zeros' => ['RO0012345678'];
        yield 'surrounding blanks' => ['  12345678  '];
    }

    public function testNullAndLetterOnlyValuesNormalizeToNull(): void
    {
        self::assertNull(CuiNormalizer::normalize(null));
        self::assertNull(CuiNormalizer::normalize(''));
        self::assertNull(CuiNormalizer::normalize('RO'));
        self::assertNull(CuiNormalizer::normalize('n/a'));
    }

    public function testAllZerosKeepTheirDigits(): void
    {
        self::assertSame('000', CuiNormalizer::normalize('000'));
    }

    public function testSameNeedsBothCodesPresentAndEqual(): void
    {
        self::assertTrue(CuiNormalizer::same('RO12345678', '12345678'));
        self::assertFalse(CuiNormalizer::same('RO12345678', '87654321'));
        self::assertFalse(CuiNormalizer::same(null, '12345678'));
        self::assertFalse(CuiNormalizer::same(null, null));
    }
}
