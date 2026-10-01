<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ConflictOption;
use App\DTO\Extraction\DetectedDataSection;
use App\DTO\Extraction\PrefillConflict;
use App\DTO\Wizard\Step1CreditorData;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step3ClaimData;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Enum\LegalGroundCategory;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Service\Extraction\DetectedDataPreviewBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The lawyer read "10/11 · 91%" on a creditor with every identity field found
 * and asked what was still missing. Nothing was: the denominator counted a CNP
 * on a legal person and other fields the case never needs. These tests pin the
 * rule that every number in the card can be counted off the rows below it.
 */
final class DetectedDataPreviewBuilderTest extends TestCase
{
    private DetectedDataPreviewBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new DetectedDataPreviewBuilder();
    }

    public function testACompleteLegalPersonCreditorIsAtOneHundredPercent(): void
    {
        $creditor = $this->section('creditor', creditor: $this->fullCreditor());

        self::assertSame(6, $creditor->filled);
        self::assertSame(6, $creditor->required);
        self::assertSame(100, $creditor->percent());
        self::assertSame([], $creditor->missing);
        self::assertNotContains('personType', $this->fields($creditor));
        self::assertNotContains('personalId', $this->fields($creditor));
    }

    public function testOptionalCreditorValuesAreShownButNotCounted(): void
    {
        $creditor = $this->section('creditor', creditor: $this->fullCreditor(iban: 'RO49AAAA1B31007593840000', bankName: 'BRD'));

        self::assertSame(6, $creditor->required);
        self::assertContains('iban', $this->fields($creditor));
        self::assertContains('bankName', $this->fields($creditor));
        self::assertSame('iban', $this->row($creditor, 'iban')['format']);
    }

    public function testAMissingCountyIsNamedAndLowersTheCount(): void
    {
        $data = $this->fullCreditor();
        $data->addressCounty = null;
        $data->autoFilled = array_values(array_diff($data->autoFilled, ['addressCounty']));

        $creditor = $this->section('creditor', creditor: $data);

        self::assertSame(5, $creditor->filled);
        self::assertSame(['addressCounty'], $creditor->missing);
        self::assertSame(83, $creditor->percent());
    }

    public function testADefaultValueNotReadFromTheDocumentsIsNotShown(): void
    {
        // The DTO starts as PJ and RON by default; neither was "detected".
        $creditor = $this->section('creditor', creditor: new Step1CreditorData(personType: PersonType::PJ, name: 'Alfa SRL', autoFilled: ['name']));

        self::assertSame(['name'], $this->fields($creditor));
        self::assertSame(1, $creditor->filled);
    }

    public function testAFieldTheDocumentsDisagreeOnIsShownButNotCounted(): void
    {
        $debtor = $this->section('debtor', debtor: $this->fullDebtor(), conflicts: [$this->countyConflict()]);

        self::assertSame(5, $debtor->filled);
        self::assertTrue($this->row($debtor, 'addressCounty')['inConflict']);
        self::assertSame([], $debtor->missing);
    }

    public function testAConflictTheLawyerSettledCountsAgain(): void
    {
        $conflict = $this->countyConflict();

        $debtor = $this->builder->build(
            new Step1CreditorData(),
            $this->fullDebtor(),
            new Step3ClaimData(),
            [$conflict],
            null,
            [$conflict->key() => true],
        )[1];

        self::assertSame(6, $debtor->filled);
        self::assertFalse($this->row($debtor, 'addressCounty')['inConflict']);
    }

    public function testAWarningWithNothingToChooseDoesNotHoldTheFieldBack(): void
    {
        // An identity completed from a second document: one option, nothing
        // for the lawyer to choose, so the value counts.
        $warning = new PrefillConflict(ConflictScope::CREDITOR, ConflictSeverity::WARNING, 'wizard.conflict.party.identity_completed', field: 'cui', options: [new ConflictOption('15663826', 2)]);

        $creditor = $this->section('creditor', creditor: $this->fullCreditor(), conflicts: [$warning]);

        self::assertSame(6, $creditor->filled);
        self::assertFalse($this->row($creditor, 'cui')['inConflict']);
    }

    private function countyConflict(): PrefillConflict
    {
        return new PrefillConflict(
            ConflictScope::DEBTOR,
            ConflictSeverity::WARNING,
            'wizard.conflict.field.county',
            field: 'county',
            options: [new ConflictOption('București', 1), new ConflictOption('Ilfov', 2)],
        );
    }

    public function testSeveralDebtorsAskForAChoiceInsteadOfACount(): void
    {
        $conflict = new PrefillConflict(ConflictScope::DEBTOR_SET, ConflictSeverity::ERROR, 'wizard.conflict.debtor_set.choose');

        self::assertTrue($this->section('debtor', debtor: $this->fullDebtor(), conflicts: [$conflict])->choiceRequired);
    }

    public function testAClaimWithSumAndDueDateIsComplete(): void
    {
        $claim = $this->section('claim', claim: new Step3ClaimData(
            amount: 29003.4,
            currency: 'RON',
            dueDate: new \DateTimeImmutable('2026-06-22'),
            legalGround: LegalGroundCategory::CONTRACT_LOCATIUNE,
            description: 'Chirie mai',
            invoiceNumber: 'JSA9581',
            invoiceDate: new \DateTimeImmutable('2026-06-17'),
            contractDate: new \DateTimeImmutable('2016-11-14'),
            contractReference: 'contract cadru de locatiune',
            autoFilled: ['amount', 'currency', 'dueDate', 'legalGround', 'description', 'invoiceNumber', 'invoiceDate', 'contractDate', 'contractReference'],
        ));

        self::assertSame(2, $claim->required);
        self::assertSame(100, $claim->percent());
        self::assertSame('29.003,40 RON', $this->row($claim, 'amount')['value']);
        self::assertSame(['value' => 'enum.legal_ground_category.CONTRACT_LOCATIUNE', 'format' => 'trans', 'inConflict' => false], $this->row($claim, 'legalGround'));
        self::assertSame('22.06.2026', $this->row($claim, 'dueDate')['value']);
        self::assertContains('contractReference', $this->fields($claim));
        self::assertNotContains('currency', $this->fields($claim), 'the currency is printed with the sum');
    }

    public function testTheLegalPenaltyAddsNoRowsAndCostsNothing(): void
    {
        $claim = $this->section('claim', claim: new Step3ClaimData(
            amount: 100.0,
            dueDate: new \DateTimeImmutable('2026-06-22'),
            penaltyType: PenaltyType::LEGAL_PENALIZATOARE,
            autoFilled: ['amount', 'dueDate'],
        ));

        self::assertNotContains('penaltyType', $this->fields($claim));
        self::assertSame(100, $claim->percent());
    }

    public function testAContractualPenaltyIsShownWithItsRate(): void
    {
        $claim = $this->section('claim', claim: new Step3ClaimData(
            amount: 100.0,
            dueDate: new \DateTimeImmutable('2026-06-22'),
            penaltyType: PenaltyType::CONTRACTUAL,
            contractualPenaltyRate: 0.1,
            autoFilled: ['amount', 'dueDate', 'penaltyType', 'contractualPenaltyRate'],
        ));

        self::assertSame('0,1', $this->row($claim, 'contractualPenaltyRate')['value']);
        self::assertSame(2, $claim->required);
    }

    public function testAForeignCurrencyClaimNeedsTheInvoiceDate(): void
    {
        $claim = $this->section('claim', claim: new Step3ClaimData(
            amount: 100.0,
            currency: 'EUR',
            dueDate: new \DateTimeImmutable('2026-06-22'),
            autoFilled: ['amount', 'currency', 'dueDate'],
        ));

        self::assertSame(3, $claim->required);
        self::assertSame(['invoiceDate'], $claim->missing);
    }

    public function testSeveralInvoicesCountTheTotalNotOneInvoice(): void
    {
        $claim = $this->section('claim', claim: new Step3ClaimData(
            amount: 100.0,
            dueDate: new \DateTimeImmutable('2026-06-22'),
            invoiceNumber: 'F1',
            legalGround: LegalGroundCategory::FACTURA_ACCEPTATA,
            autoFilled: ['amount', 'dueDate', 'invoiceNumber', 'legalGround'],
        ), positions: ['count' => 2, 'principal' => 300.0, 'currency' => 'RON', 'earliestDueDate' => new \DateTimeImmutable('2026-05-01')]);

        self::assertSame(100, $claim->percent());
        self::assertSame(['legalGround'], $this->fields($claim));
    }

    public function testEmptyDocumentsListEverythingAsMissing(): void
    {
        $debtor = $this->section('debtor');

        self::assertFalse($debtor->hasData());
        self::assertSame(DetectedDataPreviewBuilder::PARTY_REQUIRED, $debtor->missing);
        self::assertSame(0, $debtor->percent());
    }

    /**
     * @param list<PrefillConflict> $conflicts
     * @param ?array{count: int, principal: float, currency: string, earliestDueDate: ?\DateTimeInterface} $positions
     */
    private function section(
        string $key,
        ?Step1CreditorData $creditor = null,
        ?Step2DebtorEntry $debtor = null,
        ?Step3ClaimData $claim = null,
        array $conflicts = [],
        ?array $positions = null,
    ): DetectedDataSection {
        $sections = $this->builder->build(
            $creditor ?? new Step1CreditorData(),
            $debtor ?? new Step2DebtorEntry(),
            $claim ?? new Step3ClaimData(),
            $conflicts,
            $positions,
        );
        foreach ($sections as $section) {
            if ($section->key === $key) {
                return $section;
            }
        }
        self::fail('No section ' . $key);
    }

    /** @return list<string> */
    private function fields(DetectedDataSection $section): array
    {
        return array_map(static fn ($row) => $row->field, $section->rows);
    }

    /** @return array{value: string, format: string, inConflict: bool} */
    private function row(DetectedDataSection $section, string $field): array
    {
        foreach ($section->rows as $row) {
            if ($row->field === $field) {
                return ['value' => $row->value, 'format' => $row->format, 'inConflict' => $row->inConflict];
            }
        }
        self::fail('No row ' . $field);
    }

    private function fullCreditor(?string $iban = null, ?string $bankName = null): Step1CreditorData
    {
        $auto = ['personType', 'name', 'cui', 'onrcNumber', 'address', 'addressCounty', 'addressLocality'];
        if ($iban !== null) {
            $auto[] = 'iban';
        }
        if ($bankName !== null) {
            $auto[] = 'bankName';
        }

        return new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'JURESSA NET SRL',
            cui: '15663826',
            onrcNumber: 'J40/11043/2003',
            address: 'Str. Turturelelor, nr. 50, etaj 4',
            addressCounty: 'București',
            addressLocality: 'Sector 3',
            iban: $iban,
            bankName: $bankName,
            autoFilled: $auto,
        );
    }

    private function fullDebtor(): Step2DebtorEntry
    {
        return new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'ONLINE ADVERTISING SRL',
            cui: '36736564',
            onrcNumber: 'J40/14941/2016',
            address: 'Str. Azurului 25',
            addressCounty: 'București',
            addressLocality: 'Sector 1',
            autoFilled: ['personType', 'name', 'cui', 'onrcNumber', 'address', 'addressCounty', 'addressLocality'],
        );
    }
}
