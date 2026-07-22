<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\FieldSource;
use App\Enum\ConflictScope;
use App\Enum\DocumentType;
use App\Enum\FieldGroup;
use App\Service\Extraction\CoherentAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Two runs of the wizard over one set of documents must produce one case. If
 * the aggregation is not a total order, the same file prefills differently on
 * reload and nobody can say which filing the lawyer reviewed. Every case here
 * feeds the same sources in two orders and demands byte-identical output; the
 * pointed cases are the ones where only the tie-break can decide.
 */
final class AggregationDeterminismAdversarialTest extends TestCase
{
    private CoherentAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new CoherentAggregator();
    }

    public function testEqualScoreOnTwoDocumentsIsSettledByTheDocumentId(): void
    {
        // Identical type, identical confidence, contradictory identity: nothing
        // but the document id can break this, and it must break it the same way
        // whichever order the loader returned.
        $seven = $this->party(7, DocumentType::FACTURA, ['name' => 'Sapte SRL', 'cui' => '77777777']);
        $four = $this->party(4, DocumentType::FACTURA, ['name' => 'Patru SRL', 'cui' => '44444444']);

        $forward = $this->aggregateClaimlikeParty([$seven, $four]);
        $backward = $this->aggregateClaimlikeParty([$four, $seven]);

        self::assertSame('Patru SRL', $forward->values['name'], 'the lowest document id wins the tie');
        self::assertEquals($forward->values, $backward->values);
        self::assertEquals($forward->provenance, $backward->provenance);
    }

    public function testEqualScoreTieBreakHoldsAcrossEveryGroupAtOnce(): void
    {
        // Two full parties at identical confidence. The winner has to be the
        // same document for identity, contact and banking together, or the card
        // becomes a chimera assembled deterministically, which is no better.
        $low = $this->party(3, DocumentType::FACTURA, [
            'name' => 'Egal SRL', 'cui' => '33333333', 'address' => 'Str. Trei 3', 'iban' => 'RO03BANK0000000000000003',
        ]);
        $high = $this->party(8, DocumentType::FACTURA, [
            'name' => 'Egal SRL', 'cui' => '33333333', 'address' => 'Str. Opt 8', 'iban' => 'RO08BANK0000000000000008',
        ]);

        $forward = $this->aggregateClaimlikeParty([$low, $high]);
        $backward = $this->aggregateClaimlikeParty([$high, $low]);

        self::assertEquals($forward->values, $backward->values);
        self::assertSame(3, $forward->provenance['address'], 'the lower id owns the whole card');
        self::assertSame(3, $forward->provenance['iban']);
    }

    public function testAThreeWayEqualTieIsFullyOrdered(): void
    {
        $sources = [
            $this->party(5, DocumentType::FACTURA, ['name' => 'Cinci SRL', 'cui' => '55555555']),
            $this->party(2, DocumentType::FACTURA, ['name' => 'Doi SRL', 'cui' => '22222222']),
            $this->party(9, DocumentType::FACTURA, ['name' => 'Noua SRL', 'cui' => '99999999']),
        ];

        $a = $this->aggregateClaimlikeParty($sources);
        $b = $this->aggregateClaimlikeParty(array_reverse($sources));
        $c = $this->aggregateClaimlikeParty([$sources[1], $sources[2], $sources[0]]);

        self::assertSame('Doi SRL', $a->values['name'], 'id 2 is lowest');
        self::assertEquals($a->values, $b->values);
        self::assertEquals($a->values, $c->values);
        self::assertEquals($a->conflicts, $b->conflicts);
    }

    public function testAuthorityBeatsConfidenceDeterministically(): void
    {
        // The contract is less sure of itself than the invoice but outranks it
        // on identity. No amount of confidence may overturn the rank, and the
        // order of the inputs may not change that.
        $contract = new FieldSource(
            documentId: 2,
            documentType: DocumentType::CONTRACT,
            values: ['name' => 'Contractuala SRL', 'cui' => '11111111'],
            confidence: ['name' => 0.80, 'cui' => 0.80],
        );
        $invoice = new FieldSource(
            documentId: 1,
            documentType: DocumentType::FACTURA,
            values: ['name' => 'Facturata SRL', 'cui' => '22222222'],
            confidence: ['name' => 0.99, 'cui' => 0.99],
        );

        $forward = $this->aggregateClaimlikeParty([$contract, $invoice]);
        $backward = $this->aggregateClaimlikeParty([$invoice, $contract]);

        self::assertSame('Contractuala SRL', $forward->values['name'], 'authority, not confidence, decides identity');
        self::assertEquals($forward->values, $backward->values);
    }

    /**
     * @param list<FieldSource> $sources
     */
    private function aggregateClaimlikeParty(array $sources): \App\DTO\Extraction\AggregatedFields
    {
        $fields = ['name', 'cui', 'personalId', 'address', 'county', 'locality', 'email', 'iban'];
        $map = FieldGroup::partyFieldMap();
        $groups = [];
        foreach ($fields as $field) {
            $groups[$field] = $map[$field];
        }

        return $this->aggregator->aggregate($sources, $groups, ConflictScope::DEBTOR);
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
