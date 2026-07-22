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
 * The party the lawyer ends up with after choosing between documents.
 *
 * The aggregation refuses to build a company out of two files. The panel hands
 * the same power to the lawyer, one field at a time, so the same rule has to
 * hold for what they pick: a name read from one document and a registration
 * number read from another describe a legal person that exists nowhere, and the
 * court is asked to summon it (CPC art. 194 lit. a).
 */
final class ConflictChoiceCoherenceTest extends TestCase
{
    private CoherentAggregator $aggregator;
    private ConflictResolutionService $resolutions;

    protected function setUp(): void
    {
        $this->aggregator = new CoherentAggregator();
        $this->resolutions = new ConflictResolutionService();
    }

    /**
     * Both fields of the identity are put to the lawyer, and they answer the
     * one that stopped them. The decision has to carry the party, not the
     * single field it was made on.
     */
    public function testChoosingTheNumberOfOneDocumentBringsItsNameAlong(): void
    {
        $conflicts = $this->identityConflicts();

        $resolved = $this->resolutions->collect(
            [$conflicts[1]->key() => '1'],
            [],
            $conflicts,
            [],
        );

        $result = $this->aggregateWithPins($resolved);

        self::assertSame('BETA LOGISTIC SRL', $result->values['name']);
        self::assertSame('RO222222', $result->values['cui']);
    }

    /**
     * The accident the panel makes easy: one radio answered on the blocking
     * field, another left over from an earlier visit on the field beside it.
     * Two files, one party, and nothing on screen saying so.
     */
    public function testTwoChoicesOnTwoDocumentsCannotBuildAPartyOutOfBoth(): void
    {
        $conflicts = $this->identityConflicts();

        $resolved = $this->resolutions->collect(
            [$conflicts[0]->key() => '0', $conflicts[1]->key() => '1'],
            [],
            $conflicts,
            [],
        );

        $result = $this->aggregateWithPins($resolved);

        self::assertSame(
            $result->provenance['name'] ?? null,
            $result->provenance['cui'] ?? null,
            'The name and the number of one party have to be read from one file',
        );
        self::assertSame('BETA LOGISTIC SRL', $result->values['name'], 'The blocking field carries the party');
        self::assertSame('RO222222', $result->values['cui']);
        self::assertSame(
            1,
            $resolved[$conflicts[0]->key()]->optionIndex,
            'The panel has to show the name decision moved onto the chosen file, not the one it contradicts',
        );
    }

    /**
     * The same, arriving at the aggregation directly. Nothing downstream may
     * depend on the collection step having tidied the decisions up first: a
     * session written before this rule existed would otherwise still file a
     * party built out of two documents.
     */
    public function testTheAggregationRefusesContradictoryPinsOnItsOwn(): void
    {
        $result = $this->aggregateWithPins([
            'a' => new ConflictResolution(
                conflictKey: 'a',
                scope: ConflictScope::CREDITOR,
                field: 'name',
                entityKey: null,
                value: 'ALFA CONSTRUCT SRL',
                optionIndex: 0,
                documentId: 10,
                optionSignature: 'ALFA CONSTRUCT SRL',
            ),
            'b' => new ConflictResolution(
                conflictKey: 'b',
                scope: ConflictScope::CREDITOR,
                field: 'cui',
                entityKey: null,
                value: 'RO222222',
                optionIndex: 1,
                documentId: 20,
                optionSignature: 'RO222222',
            ),
        ]);

        self::assertSame(
            $result->provenance['name'] ?? null,
            $result->provenance['cui'] ?? null,
            'One group, one document, whatever the session carries',
        );
    }

    /**
     * A typed value has no document behind it and no group to move, so it stays
     * on its own field and stops claiming a document said it.
     */
    public function testATypedValueStaysOnItsFieldAndLosesItsProvenance(): void
    {
        $conflicts = $this->identityConflicts();

        $resolved = $this->resolutions->collect(
            [$conflicts[1]->key() => ConflictResolutionService::MANUAL_CHOICE],
            [$conflicts[1]->key() => 'RO42000006'],
            $conflicts,
            [],
        );

        $result = $this->aggregateWithPins($resolved);

        self::assertSame('RO42000006', $result->values['cui']);
        self::assertSame('ALFA CONSTRUCT SRL', $result->values['name'], 'Nothing moved the party');
        self::assertArrayNotHasKey('cui', $result->provenance);
        self::assertNotContains('cui', $result->autoFilled);
    }

    /**
     * @param array<string, ConflictResolution> $resolutions
     */
    private function aggregateWithPins(array $resolutions): \App\DTO\Extraction\AggregatedFields
    {
        return $this->aggregator->aggregate(
            $this->sources(),
            ['name' => FieldGroup::PARTY_IDENTITY, 'cui' => FieldGroup::PARTY_IDENTITY],
            ConflictScope::CREDITOR,
            null,
            $this->resolutions->pinsFor($resolutions, ConflictScope::CREDITOR),
        );
    }

    /**
     * @return list<FieldSource>
     */
    private function sources(): array
    {
        return [
            new FieldSource(
                documentId: 10,
                documentType: DocumentType::CONTRACT,
                values: ['name' => 'ALFA CONSTRUCT SRL', 'cui' => 'RO111111'],
                confidence: ['name' => 0.95, 'cui' => 0.95],
            ),
            new FieldSource(
                documentId: 20,
                documentType: DocumentType::FACTURA,
                values: ['name' => 'BETA LOGISTIC SRL', 'cui' => 'RO222222'],
                confidence: ['name' => 0.95, 'cui' => 0.95],
            ),
        ];
    }

    /**
     * The two disagreements the identity of one party produces: the name, which
     * only warns, and the number, which blocks.
     *
     * @return list<PrefillConflict>
     */
    private function identityConflicts(): array
    {
        return [
            new PrefillConflict(
                scope: ConflictScope::CREDITOR,
                severity: ConflictSeverity::WARNING,
                messageKey: 'wizard.conflict.field.name',
                field: 'name',
                options: [
                    new ConflictOption(value: 'ALFA CONSTRUCT SRL', documentId: 10, documentType: DocumentType::CONTRACT, confidence: 0.95),
                    new ConflictOption(value: 'BETA LOGISTIC SRL', documentId: 20, documentType: DocumentType::FACTURA, confidence: 0.95),
                ],
                suggestedIndex: 0,
            ),
            new PrefillConflict(
                scope: ConflictScope::CREDITOR,
                severity: ConflictSeverity::ERROR,
                messageKey: 'wizard.conflict.field.cui',
                field: 'cui',
                options: [
                    new ConflictOption(value: 'RO111111', documentId: 10, documentType: DocumentType::CONTRACT, confidence: 0.95),
                    new ConflictOption(value: 'RO222222', documentId: 20, documentType: DocumentType::FACTURA, confidence: 0.95),
                ],
                suggestedIndex: 0,
            ),
        ];
    }
}
