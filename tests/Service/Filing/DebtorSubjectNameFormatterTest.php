<?php

declare(strict_types=1);

namespace App\Tests\Service\Filing;

use App\Enum\PersonType;
use App\Service\Filing\DebtorSubjectNameFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DebtorSubjectNameFormatterTest extends TestCase
{
    /** @return iterable<string, array{PersonType, string, ?string, string}> */
    public static function names(): iterable
    {
        yield 'company keeps its full name' => [PersonType::PJ, 'SC Debitor Exemplu SRL', 'RO123', 'SC Debitor Exemplu SRL'];
        yield 'natural person to initials' => [PersonType::PF, 'Ion Popescu', null, 'I.P.'];
        yield 'hyphenated and several first names' => [PersonType::PF, 'Ana-Maria Elena Ionescu', null, 'A.M.E.I.'];
        yield 'diacritics kept in initials' => [PersonType::PF, 'Ștefan Țepeș', null, 'Ș.Ț.'];
        yield 'PFA with CUI' => [PersonType::PF, 'Ion Popescu PFA', '12345678', 'I.P. PFA, CUI 12345678'];
        yield 'PFA spelled out, registered as company' => [PersonType::PJ, 'Popescu Ion Persoană Fizică Autorizată', '987', 'P.I. PFA, CUI 987'];
        yield 'II detected in the name' => [PersonType::PJ, 'Ionescu Maria Întreprindere Individuală', '555', 'I.M. II, CUI 555'];
        yield 'II abbreviation' => [PersonType::PJ, 'Vasile Dan II', null, 'V.D. II'];
        yield 'company whose name contains II' => [PersonType::PJ, 'Hotel Parc II SRL', 'RO42', 'Hotel Parc II SRL'];
        yield 'company whose name contains IF' => [PersonType::PJ, 'Construct IF S.R.L.', 'RO43', 'Construct IF S.R.L.'];
        yield 'company with PFA in the name, SA form' => [PersonType::PJ, 'Servicii PFA Consult SA', 'RO44', 'Servicii PFA Consult SA'];
        yield 'PF without CUI never gets one' => [PersonType::PF, 'Maria Pop', '', 'M.P.'];
    }

    #[DataProvider('names')]
    public function testFormatsDebtorForTheSubject(PersonType $type, string $name, ?string $cui, string $expected): void
    {
        self::assertSame($expected, (new DebtorSubjectNameFormatter())->format($type, $name, $cui));
    }

    public function testSeveralDebtorsAreNamedByTheFirstOnly(): void
    {
        $label = (new DebtorSubjectNameFormatter())->formatDebtors([
            ['type' => PersonType::PF, 'name' => 'Ion Popescu', 'cui' => null],
            ['type' => PersonType::PJ, 'name' => 'SC Alta SRL', 'cui' => 'RO1'],
        ], ' și alții');

        self::assertSame('I.P. și alții', $label);
    }

    public function testCompanyNameContainingIiInsideAWordIsNotAProfessional(): void
    {
        self::assertSame('SC Ciocanii Construct SRL', (new DebtorSubjectNameFormatter())->format(PersonType::PJ, 'SC Ciocanii Construct SRL', 'RO9'));
    }
}
