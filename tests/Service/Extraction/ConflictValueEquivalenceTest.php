<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\Service\Extraction\ConflictValueEquivalence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The spellings come from the documents the reviewing lawyer uploaded (cases
 * 2, 3, 5 and 6 of the August 2026 review), where each pair was put to them as
 * a disagreement although both documents state the same thing.
 */
final class ConflictValueEquivalenceTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function sameThing(): iterable
    {
        yield 'seat with and without postal code' => ['address', 'str. Bihorului, nr.10', 'Str. Bihorului, Nr. 10, C.P. 400295'];
        yield 'seat spelled out' => ['address', 'strada Bihorului, numărul 10', 'Str. Bihorului, Nr. 10'];
        yield 'locality repeated in the street line' => ['address', 'STR LINIA DE CENTURA, NR. 50, TARLAUA 44, PARCELA 337', 'STEFANESTII DE JOS, STR LINIA DE CENTURA, NR. 50, TARLAUA 44, PARCELA 337'];
        yield 'boulevard abbreviations' => ['address', 'B-dul Pipera nr.1/VII', 'Bld. Pipera, nr.1/VII'];
        yield 'locality with and without diacritics' => ['locality', 'Grumazești', 'Grumăzești'];
        yield 'locality with unit prefix' => ['locality', 'Municipiul Cluj-Napoca', 'Cluj-Napoca'];
        yield 'sector spelled out' => ['locality', 'Sectorul 6', 'Sector 6'];
        yield 'county with prefix' => ['county', 'Jud. Ilfov', 'Ilfov'];
        yield 'representative with diacritics and dash' => ['legalRepresentative', 'Pavăl Marco – Gabriel', 'Paval Marco Gabriel'];
        yield 'administrator without middle name, reversed' => ['administrator', 'Marchis Bogdan', 'Bogdan Marius Marchiș'];
        yield 'iban printed in groups' => ['iban', 'RO24 CECE NT04 30RO N101 9827', 'RO24CECENT0430RON1019827'];
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function differentThings(): iterable
    {
        yield 'another street number' => ['address', 'Str. Preciziei, Nr. 24, Cladire A1', 'strada Preciziei, numărul 24A, clădire A1'];
        yield 'strada against bulevard' => ['address', 'strada Preciziei 24A', 'bd. Preciziei 24A'];
        yield 'another apartment' => ['address', 'Str. Linia de Centura nr. 50', 'Str. Linia de Centura nr. 50, Bl Q16, AP. 002'];
        yield 'another sector' => ['locality', 'Sector 2', 'Sector 3'];
        yield 'another locality' => ['locality', 'Voluntari', 'Ștefăneștii de Jos'];
        yield 'another person' => ['legalRepresentative', 'Amariei Petru', 'Bumbea Sebi'];
        yield 'one shared surname only' => ['administrator', 'Popescu Ion', 'Popescu Maria'];
        yield 'another account' => ['iban', 'RO24CECENT0430RON1019827', 'RO31RNCB0199005307310001'];
        yield 'a field without a rule' => ['name', 'Alfa SRL', 'ALFA SRL'];
    }

    #[DataProvider('sameThing')]
    public function testTwoSpellingsOfOneFactAreTheSame(string $field, string $a, string $b): void
    {
        $equivalence = new ConflictValueEquivalence();

        self::assertTrue($equivalence->same($field, $a, $b));
        self::assertTrue($equivalence->same($field, $b, $a), 'the relation is symmetric');
    }

    #[DataProvider('differentThings')]
    public function testDifferentFactsStayDifferent(string $field, string $a, string $b): void
    {
        $equivalence = new ConflictValueEquivalence();

        self::assertFalse($equivalence->same($field, $a, $b));
        self::assertFalse($equivalence->same($field, $b, $a));
    }
}
