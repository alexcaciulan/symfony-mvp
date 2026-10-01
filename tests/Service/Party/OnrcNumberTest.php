<?php

declare(strict_types=1);

namespace App\Tests\Service\Party;

use App\Service\Party\OnrcNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OnrcNumberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sameRegistrations(): iterable
    {
        yield 'registry vs invoice' => ['J2003011043402', 'J40/11043/2003'];
        yield 'padded sequence' => ['J40/011043/2003', 'J40/11043/2003'];
        yield 'spaces and case' => ['j 40 / 11043 / 2003', 'J40/11043/2003'];
        yield 'single-digit county' => ['J2016001234052', 'J5/1234/2016'];
        yield 'sole proprietorship' => ['F2010000123124', 'F12/123/2010'];
        yield 'cooperative' => ['C2001000045011', 'C1/45/2001'];
    }

    #[DataProvider('sameRegistrations')]
    public function testBothSpellingsNameTheSameRegistration(string $a, string $b): void
    {
        self::assertTrue(OnrcNumber::sameRegistration($a, $b));
        self::assertNotNull(OnrcNumber::key($a));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function differentRegistrations(): iterable
    {
        yield 'other county' => ['J2003011043122', 'J40/11043/2003'];
        yield 'other sequence' => ['J2003011044402', 'J40/11043/2003'];
        yield 'other year' => ['J2004011043402', 'J40/11043/2003'];
        yield 'other letter' => ['F2003011043402', 'J40/11043/2003'];
    }

    #[DataProvider('differentRegistrations')]
    public function testADifferentPartMeansADifferentRegistration(string $a, string $b): void
    {
        self::assertFalse(OnrcNumber::sameRegistration($a, $b));
    }

    public function testAnUnparsableValueFallsBackToAStrictComparison(): void
    {
        self::assertNull(OnrcNumber::key('nr. ORC lipsă'));
        self::assertTrue(OnrcNumber::sameRegistration(' abc ', 'ABC'));
        self::assertFalse(OnrcNumber::sameRegistration('abc', 'J40/11043/2003'));
        self::assertNull(OnrcNumber::key(null));
    }
}
