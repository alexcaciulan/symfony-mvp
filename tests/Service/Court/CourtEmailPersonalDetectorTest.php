<?php

declare(strict_types=1);

namespace App\Tests\Service\Court;

use App\Service\Court\CourtEmailPersonalDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CourtEmailPersonalDetectorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function nominalAddresses(): iterable
    {
        yield 'first.last' => ['ion.popescu@just.ro'];
        yield 'compound first name' => ['maria-elena.ionescu@just.ro'];
        yield 'uppercase and spaces' => ['  Ion.Popescu@JUST.ro '];
        yield 'subdomain' => ['ana.marin@jud-sector1.just.ro'];
        yield 'diacritics' => ['ștefan.țăranu@just.ro'];
    }

    #[DataProvider('nominalAddresses')]
    public function testPersonShapedJustRoAddressIsNominal(string $email): void
    {
        self::assertTrue((new CourtEmailPersonalDetector())->isNominal($email));
    }

    /** @return iterable<string, array{string}> */
    public static function institutionalAddresses(): iterable
    {
        yield 'registry section' => ['registratura.civil@just.ro'];
        yield 'court code with digit' => ['jud-sector1@just.ro'];
        yield 'tribunal name' => ['tribunalul.cluj@just.ro'];
        yield 'single segment' => ['contact@just.ro'];
        yield 'not just.ro' => ['ion.popescu@example.com'];
        yield 'lookalike domain' => ['ion.popescu@notjust.ro'];
        yield 'four segments' => ['a.b.c.d@just.ro'];
        yield 'no at sign' => ['ion.popescu'];
    }

    #[DataProvider('institutionalAddresses')]
    public function testInstitutionalOrForeignAddressIsNotNominal(string $email): void
    {
        self::assertFalse((new CourtEmailPersonalDetector())->isNominal($email));
    }
}
