<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\FieldSource;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use App\Enum\FieldGroup;
use App\Service\Extraction\CoherentAggregator;
use PHPUnit\Framework\TestCase;

final class CoherentAggregatorTest extends TestCase
{
    private CoherentAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new CoherentAggregator();
    }

    public function testTheWinningDocumentGivesItsWholeIdentityGroup(): void
    {
        // The point of the whole class: the name and the registration number
        // must come from one document, or the filing names a company that does
        // not exist.
        $contract = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa Construct SRL', 'cui' => '11111111']);
        $invoice = $this->party(2, DocumentType::FACTURA, ['name' => 'Beta Logistic SRL', 'cui' => '22222222'], 0.99);

        $result = $this->aggregateParty([$contract, $invoice]);

        self::assertSame('Alfa Construct SRL', $result->values['name']);
        self::assertSame('11111111', $result->values['cui']);
    }

    public function testDifferentRegistrationNumbersBlockGapFilling(): void
    {
        // The invoice knows an address the contract does not state, but the two
        // documents are about different companies, so that address belongs to
        // somebody else.
        $contract = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa SRL', 'cui' => '11111111']);
        $invoice = $this->party(2, DocumentType::FACTURA, [
            'name' => 'Beta SRL',
            'cui' => '22222222',
            'address' => 'Str. Gresita 5',
        ]);

        $result = $this->aggregateParty([$contract, $invoice]);

        self::assertArrayNotHasKey('address', $result->values);
    }

    public function testTheSameRegistrationNumberAllowsGapFilling(): void
    {
        $contract = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa SRL', 'cui' => 'RO 11111111']);
        $invoice = $this->party(2, DocumentType::FACTURA, [
            'name' => 'ALFA S.R.L.',
            'cui' => '11111111',
            'address' => 'Str. Corecta 5',
        ]);

        $result = $this->aggregateParty([$contract, $invoice]);

        self::assertSame('Str. Corecta 5', $result->values['address']);
        self::assertSame(2, $result->provenance['address'], 'the address is traceable to the invoice');
    }

    public function testDifferentPersonalNumbersBlockGapFilling(): void
    {
        $first = $this->party(1, DocumentType::CONTRACT, ['name' => 'Ion Popescu', 'personalId' => '1980715221232']);
        $second = $this->party(2, DocumentType::FACTURA, [
            'name' => 'Ion Popescu',
            'personalId' => '2980715221237',
            'address' => 'Str. Alta 9',
        ]);

        $result = $this->aggregateParty([$first, $second]);

        self::assertArrayNotHasKey('address', $result->values);
    }

    public function testWithoutRegistrationNumbersTheNamesHaveToMatch(): void
    {
        $near = $this->party(2, DocumentType::FACTURA, ['name' => 'S.C. ALFA CONSTRUCT S.R.L.', 'address' => 'Str. A 1']);
        $far = $this->party(3, DocumentType::FACTURA, ['name' => 'Gamma Distribution SRL', 'phone' => '0722333444']);
        $winner = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa Construct SRL']);

        $result = $this->aggregateParty([$winner, $near, $far]);

        self::assertSame('Str. A 1', $result->values['address'], 'a near-identical name fills the gap');
        self::assertArrayNotHasKey('phone', $result->values, 'an unrelated name does not');
    }

    public function testTwoRunsOverTheSameSourcesProduceTheSameResult(): void
    {
        // Two equally ranked documents, identical scores: the document id has
        // to settle it, or the same case prefills differently on reload.
        $sources = [
            $this->party(7, DocumentType::FACTURA, ['name' => 'Sapte SRL', 'cui' => '77777777']),
            $this->party(4, DocumentType::FACTURA, ['name' => 'Patru SRL', 'cui' => '44444444']),
        ];

        $first = $this->aggregateParty($sources);
        $second = $this->aggregateParty(array_reverse($sources));

        self::assertSame('Patru SRL', $first->values['name'], 'the lowest document id breaks the tie');
        self::assertEquals($first->values, $second->values);
        self::assertEquals($first->provenance, $second->provenance);
    }

    public function testGroupsAreDecidedIndependentlyOfEachOther(): void
    {
        // The contract is authoritative on the penalty clause and the invoice on
        // the sum, and both statements survive in the same claim.
        $contract = new FieldSource(
            documentId: 1,
            documentType: DocumentType::CONTRACT,
            values: ['amount' => 90000.0, 'penaltyType' => 'CONTRACTUAL', 'contractualPenaltyRate' => 0.1],
            confidence: ['amount' => 0.99, 'penaltyType' => 0.85, 'contractualPenaltyRate' => 0.85],
        );
        $invoice = new FieldSource(
            documentId: 2,
            documentType: DocumentType::FACTURA,
            values: ['amount' => 12000.0, 'penaltyType' => 'CONTRACTUAL', 'contractualPenaltyRate' => 0.5],
            confidence: ['amount' => 0.85, 'penaltyType' => 0.99, 'contractualPenaltyRate' => 0.99],
        );

        $result = $this->aggregator->aggregate(
            [$contract, $invoice],
            $this->fieldGroups(['amount', 'penaltyType', 'contractualPenaltyRate'], FieldGroup::claimFieldMap()),
            ConflictScope::CLAIM,
        );

        self::assertSame(12000.0, $result->values['amount'], 'the invoice states the debt');
        self::assertSame(0.1, $result->values['contractualPenaltyRate'], 'the contract states the clause');
    }

    public function testAFieldNobodyDisputesProducesNoConflict(): void
    {
        $a = $this->party(1, DocumentType::CONTRACT, ['name' => 'S.C. Alfa S.R.L.', 'cui' => 'RO11111111']);
        $b = $this->party(2, DocumentType::FACTURA, ['name' => 'ALFA SRL', 'cui' => '11111111']);

        self::assertSame([], $this->aggregateParty([$a, $b])->conflicts);
    }

    public function testTwoRegistrationNumbersProduceABlockingConflict(): void
    {
        $a = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa SRL', 'cui' => '11111111']);
        $b = $this->party(2, DocumentType::FACTURA, ['name' => 'Beta SRL', 'cui' => '22222222']);

        $conflicts = $this->aggregateParty([$a, $b], ConflictScope::DEBTOR)->conflicts;
        $byField = [];
        foreach ($conflicts as $conflict) {
            $byField[(string) $conflict->field] = $conflict;
        }

        self::assertArrayHasKey('cui', $byField);
        self::assertSame(ConflictSeverity::ERROR, $byField['cui']->severity);
        self::assertTrue($byField['cui']->blocks());
        self::assertCount(2, $byField['cui']->options);
        self::assertSame(0, $byField['cui']->suggestedIndex);
        self::assertSame(ConflictScope::DEBTOR, $byField['cui']->scope);

        self::assertArrayHasKey('name', $byField);
        self::assertSame(ConflictSeverity::WARNING, $byField['name']->severity, 'a second spelling of a name is not blocking');
    }

    public function testValuesBelowTheConfidenceThresholdAreNotUsed(): void
    {
        $source = $this->party(1, DocumentType::FACTURA, ['name' => 'Nesigur SRL'], 0.79);

        self::assertSame([], $this->aggregateParty([$source])->values);
    }

    /**
     * @param list<FieldSource> $sources
     */
    public function testDivergingFreeProseRaisesNoConflict(): void
    {
        // Two invoices for the same service word it differently, down to the
        // diacritics. Reporting that trains the lawyer to dismiss the panel
        // that also carries contradicting tax numbers.
        $first = $this->party(1, DocumentType::FACTURA, [
            'name' => 'Alfa SRL',
            'cui' => '11111111',
            'description' => 'Servicii dezvoltare soluții software, 2 ore.',
        ]);
        $second = $this->party(2, DocumentType::FACTURA, [
            'name' => 'Alfa SRL',
            'cui' => '11111111',
            'description' => 'Servicii dezvoltare solutii software, 2 ore.',
        ]);

        $result = $this->aggregator->aggregate(
            [$first, $second],
            $this->fieldGroups(['name', 'cui', 'description'], FieldGroup::partyFieldMap() + ['description' => FieldGroup::CLAIM_BASIS]),
            ConflictScope::CLAIM,
        );

        $fields = array_map(static fn ($c) => $c->field, $result->conflicts);
        self::assertNotContains('description', $fields);
        // The value itself still comes through from the winning document.
        self::assertNotNull($result->values['description']);
    }

    private function aggregateParty(array $sources, ConflictScope $scope = ConflictScope::CREDITOR): \App\DTO\Extraction\AggregatedFields
    {
        return $this->aggregator->aggregate(
            $sources,
            $this->fieldGroups(
                ['personType', 'name', 'cui', 'personalId', 'address', 'county', 'locality', 'phone', 'iban'],
                FieldGroup::partyFieldMap(),
            ),
            $scope,
        );
    }

    /**
     * @param list<string> $fields
     * @param array<string, FieldGroup> $map
     * @return array<string, FieldGroup>
     */
    private function fieldGroups(array $fields, array $map): array
    {
        $groups = [];
        foreach ($fields as $field) {
            $groups[$field] = $map[$field];
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function party(int $id, DocumentType $type, array $values, float $confidence = 0.9): FieldSource
    {
        $scores = [];
        foreach ($values as $field => $_) {
            $scores[$field] = $confidence;
        }

        return new FieldSource(documentId: $id, documentType: $type, values: $values, confidence: $scores);
    }
}
