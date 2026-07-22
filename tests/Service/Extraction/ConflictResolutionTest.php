<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ConflictOption;
use App\DTO\Extraction\ConflictResolution;
use App\DTO\Extraction\FieldSource;
use App\DTO\Extraction\PrefillConflict;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use App\Enum\FieldGroup;
use App\Service\Extraction\CoherentAggregator;
use App\Service\Extraction\ConflictResolutionService;
use PHPUnit\Framework\TestCase;

/**
 * What the lawyer decides about a disagreement between documents has to beat
 * what the ranking decided, has to survive walking back through the wizard, and
 * has to leave a record.
 *
 * The coherence rule is the delicate part. Choosing the registration number
 * stated by the invoice cannot mean "that number, everything else as before":
 * that is precisely the party built out of two files that the aggregation
 * exists to prevent. It means the party in the file is the one the invoice
 * describes.
 */
final class ConflictResolutionTest extends TestCase
{
    private CoherentAggregator $aggregator;
    private ConflictResolutionService $service;

    protected function setUp(): void
    {
        $this->aggregator = new CoherentAggregator();
        $this->service = new ConflictResolutionService();
    }

    public function testChoosingTheOtherDocumentMovesTheWholeIdentityGroup(): void
    {
        $contract = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa Construct SRL', 'cui' => '11111111']);
        $invoice = $this->party(2, DocumentType::FACTURA, ['name' => 'Beta Logistic SRL', 'cui' => '22222222']);

        $pinned = $this->aggregateParty([$contract, $invoice], [
            'cui' => $this->pin('cui', '22222222', 1, 2),
        ]);

        self::assertSame('22222222', $pinned->values['cui']);
        // The name follows the number. Keeping "Alfa Construct SRL" here would
        // name a company that exists in no document.
        self::assertSame('Beta Logistic SRL', $pinned->values['name']);
        self::assertSame(2, $pinned->provenance['cui']);
        self::assertSame(2, $pinned->provenance['name']);
    }

    public function testTheChosenPartyAlsoDecidesTheFieldsOfEveryOtherGroup(): void
    {
        // The address decides the competent court, so it has to belong to the
        // party the lawyer chose and not to the one the ranking preferred.
        $contract = $this->party(1, DocumentType::CONTRACT, [
            'name' => 'Alfa Construct SRL',
            'cui' => '11111111',
            'address' => 'Str. Cluj 1',
        ]);
        $invoice = $this->party(2, DocumentType::FACTURA, [
            'name' => 'Beta Logistic SRL',
            'cui' => '22222222',
            'address' => 'Str. Iasi 2',
        ]);

        $pinned = $this->aggregateParty([$contract, $invoice], [
            'cui' => $this->pin('cui', '22222222', 1, 2),
        ]);

        self::assertSame('Str. Iasi 2', $pinned->values['address']);
    }

    public function testATypedValueBeatsEveryDocumentAndIsNotBadgedAuto(): void
    {
        $contract = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa Construct SRL', 'cui' => '11111111']);
        $invoice = $this->party(2, DocumentType::FACTURA, ['name' => 'Beta Logistic SRL', 'cui' => '22222222']);

        $typed = new ConflictResolution(
            conflictKey: 'creditor:-:cui:wizard.conflict.field.cui',
            scope: ConflictScope::CREDITOR,
            field: 'cui',
            entityKey: null,
            value: '33333333',
        );

        $result = $this->aggregateParty([$contract, $invoice], ['cui' => $typed]);

        self::assertSame('33333333', $result->values['cui']);
        self::assertArrayNotHasKey('cui', $result->provenance);
        self::assertNotContains('cui', $result->autoFilled);
        // The name still comes from a document, so it keeps its badge.
        self::assertContains('name', $result->autoFilled);
    }

    public function testTheOptionsKeepTheirOrderOnceAChoiceIsMade(): void
    {
        // The choice is stored as a position in the list. If pinning reordered
        // the list, the next request would read the same index as another value.
        $contract = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa Construct SRL', 'cui' => '11111111']);
        $invoice = $this->party(2, DocumentType::FACTURA, ['name' => 'Beta Logistic SRL', 'cui' => '22222222']);

        $before = $this->cuiConflict($this->aggregateParty([$contract, $invoice])->conflicts);
        $after = $this->cuiConflict($this->aggregateParty([$contract, $invoice], [
            'cui' => $this->pin('cui', '22222222', 1, 2),
        ])->conflicts);

        self::assertSame(
            array_map(static fn (ConflictOption $o): string => $o->displayValue(), $before->options),
            array_map(static fn (ConflictOption $o): string => $o->displayValue(), $after->options),
        );
    }

    public function testAChoiceIsReadOffThePostedIndex(): void
    {
        $conflict = $this->conflict('cui', ['11111111', '22222222'], ConflictSeverity::ERROR);

        $resolved = $this->service->collect([$conflict->key() => '1'], [], [$conflict], []);

        self::assertSame('22222222', $resolved[$conflict->key()]->value);
        self::assertSame(2, $resolved[$conflict->key()]->documentId);
        self::assertFalse($resolved[$conflict->key()]->isManual());
    }

    public function testATypedSumArrivesAsANumberWhateverTheDecimalMark(): void
    {
        $conflict = $this->conflict('amount', [1000.0, 1200.0], ConflictSeverity::ERROR);

        $resolved = $this->service->collect(
            [$conflict->key() => ConflictResolutionService::MANUAL_CHOICE],
            [$conflict->key() => '1 250,50'],
            [$conflict],
            [],
        );

        self::assertSame(1250.5, $resolved[$conflict->key()]->value);
    }

    public function testAnEmptyTypedValueLeavesTheConflictOpen(): void
    {
        $conflict = $this->conflict('cui', ['11111111', '22222222'], ConflictSeverity::ERROR);

        $resolved = $this->service->collect(
            [$conflict->key() => ConflictResolutionService::MANUAL_CHOICE],
            [$conflict->key() => '   '],
            [$conflict],
            [],
        );

        self::assertSame([], $resolved);
        self::assertSame([$conflict], $this->service->pendingChoices([$conflict], $resolved));
    }

    public function testAChoiceOnAnotherStepIsNotTouched(): void
    {
        $creditor = $this->conflict('cui', ['11111111', '22222222'], ConflictSeverity::ERROR);
        $elsewhere = new ConflictResolution(
            conflictKey: 'claim:-:amount:wizard.conflict.field.amount',
            scope: ConflictScope::CLAIM,
            field: 'amount',
            entityKey: null,
            value: 1000.0,
            optionIndex: 0,
        );

        $resolved = $this->service->collect(
            [$creditor->key() => '0'],
            [],
            [$creditor],
            [$elsewhere->conflictKey => $elsewhere],
        );

        self::assertArrayHasKey($elsewhere->conflictKey, $resolved);
        self::assertArrayHasKey($creditor->key(), $resolved);
    }

    public function testAChoiceThatNoLongerMatchesTheDocumentsIsDropped(): void
    {
        $conflict = $this->conflict('cui', ['11111111', '22222222'], ConflictSeverity::ERROR);
        $stale = new ConflictResolution(
            conflictKey: $conflict->key(),
            scope: ConflictScope::CREDITOR,
            field: 'cui',
            entityKey: null,
            value: '99999999',
            optionIndex: 1,
            documentId: 9,
            optionSignature: '99999999',
        );

        $kept = $this->service->reconcile([$stale->conflictKey => $stale], [$conflict]);

        self::assertSame([], $kept);
    }

    public function testAConflictWithNothingToChooseBetweenIsAcknowledgedNotResolved(): void
    {
        $optionless = new PrefillConflict(
            scope: ConflictScope::DEBTOR_SET,
            severity: ConflictSeverity::ERROR,
            messageKey: 'wizard.conflict.debtor_set.claims_span_debtors',
            field: 'debtors',
        );

        self::assertSame([], $this->service->pendingChoices([$optionless], []));
        self::assertSame([$optionless], $this->service->pendingAcknowledgement([$optionless]));
    }

    public function testTheAuditEntryStatesWhatWasOfferedAndWhatWasRetained(): void
    {
        $conflict = $this->conflict('cui', ['11111111', '22222222'], ConflictSeverity::ERROR);
        $resolved = $this->service->collect([$conflict->key() => '1'], [], [$conflict], []);

        $entries = $this->service->auditEntries([$conflict], $resolved);

        self::assertCount(1, $entries);
        self::assertSame('cui', $entries[0]['field']);
        self::assertCount(2, $entries[0]['options']);
        self::assertSame('22222222', $entries[0]['resolution']['value']);
        self::assertSame(2, $entries[0]['resolution']['documentId']);
        self::assertFalse($entries[0]['resolution']['manual']);
    }

    /**
     * @param list<PrefillConflict> $conflicts
     */
    private function cuiConflict(array $conflicts): PrefillConflict
    {
        foreach ($conflicts as $conflict) {
            if ($conflict->field === 'cui') {
                return $conflict;
            }
        }

        self::fail('no conflict was raised on the registration number');
    }

    /**
     * @param list<mixed> $values
     */
    private function conflict(string $field, array $values, ConflictSeverity $severity): PrefillConflict
    {
        $options = [];
        foreach ($values as $index => $value) {
            $options[] = new ConflictOption(
                value: $value,
                documentId: $index + 1,
                documentType: DocumentType::FACTURA,
                confidence: 0.9,
            );
        }

        return new PrefillConflict(
            scope: ConflictScope::CREDITOR,
            severity: $severity,
            messageKey: 'wizard.conflict.field.' . $field,
            field: $field,
            options: $options,
            suggestedIndex: 0,
        );
    }

    private function pin(string $field, mixed $value, int $optionIndex, int $documentId): ConflictResolution
    {
        return new ConflictResolution(
            conflictKey: 'creditor:-:' . $field . ':wizard.conflict.field.' . $field,
            scope: ConflictScope::CREDITOR,
            field: $field,
            entityKey: null,
            value: $value,
            optionIndex: $optionIndex,
            documentId: $documentId,
            documentType: DocumentType::FACTURA,
            optionSignature: (string) $value,
        );
    }

    /**
     * @param list<FieldSource> $sources
     * @param array<string, ConflictResolution> $pins
     */
    private function aggregateParty(array $sources, array $pins = []): \App\DTO\Extraction\AggregatedFields
    {
        $fields = ['personType', 'name', 'cui', 'personalId', 'address', 'county', 'locality', 'phone', 'iban'];
        $map = FieldGroup::partyFieldMap();
        $groups = [];
        foreach ($fields as $field) {
            $groups[$field] = $map[$field];
        }

        return $this->aggregator->aggregate($sources, $groups, ConflictScope::CREDITOR, null, $pins);
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
