<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\AggregatedFields;
use App\DTO\Extraction\FieldSource;
use App\Entity\Document;
use App\Enum\ConflictScope;
use App\Enum\DocumentType;
use App\Enum\FieldGroup;
use App\Repository\DocumentRepository;
use App\Service\Extraction\CoherentAggregator;
use App\Service\Extraction\PrefillFromExtractionService;
use PHPUnit\Framework\TestCase;

/**
 * The central defect the coherent aggregator exists to prevent: a party whose
 * name is read from one document and whose registration number is read from
 * another, when the two documents describe different entities. That produces a
 * legal person that does not exist, in a document filed with a court.
 *
 * Every assertion here is a pair: whatever the aggregation decides, the name
 * and the registration number must have come from the same document. A + 111 is
 * acceptable and B + 222 is acceptable; A + 222 and B + 111 are the failures.
 */
final class ChimeraGuardAdversarialTest extends TestCase
{
    private CoherentAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new CoherentAggregator();
    }

    public function testContractAndInvoiceOnDifferentEntitiesNeverCrossNameAndCui(): void
    {
        $contract = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa Construct SRL', 'cui' => '11111111']);
        $invoice = $this->party(2, DocumentType::FACTURA, ['name' => 'Beta Logistic SRL', 'cui' => '22222222'], 0.99);

        $result = $this->aggregateParty([$contract, $invoice]);

        $this->assertCoherentIdentity($result, ['Alfa Construct SRL' => '11111111', 'Beta Logistic SRL' => '22222222']);
        // The contract owns identity, so it is Alfa here specifically.
        self::assertSame('Alfa Construct SRL', $result->values['name']);
        self::assertSame('11111111', $result->values['cui']);
    }

    public function testTheHighConfidenceInvoiceCannotStealTheCuiFromTheContractName(): void
    {
        // The invoice screams its own registration number at 0.99 while the
        // contract states the name it belongs to. If field-independent maxima
        // ever creep back, this is where the name of one company acquires the
        // number of another.
        $contract = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa Construct SRL'], 0.95);
        $contract = new FieldSource(
            documentId: 1,
            documentType: DocumentType::CONTRACT,
            values: ['name' => 'Alfa Construct SRL', 'cui' => '11111111'],
            confidence: ['name' => 0.95, 'cui' => 0.82],
        );
        $invoice = new FieldSource(
            documentId: 2,
            documentType: DocumentType::FACTURA,
            values: ['name' => 'Beta Logistic SRL', 'cui' => '22222222'],
            confidence: ['name' => 0.99, 'cui' => 0.99],
        );

        $result = $this->aggregateParty([$contract, $invoice]);

        $this->assertCoherentIdentity($result, ['Alfa Construct SRL' => '11111111', 'Beta Logistic SRL' => '22222222']);
    }

    public function testTwoInvoicesWithNoContractStillNeverCross(): void
    {
        // No authoritative identity document at all: whichever invoice wins the
        // identity group, the group is taken whole and the other is barred from
        // the address it alone knows.
        $first = new FieldSource(
            documentId: 1,
            documentType: DocumentType::FACTURA,
            values: ['name' => 'Alfa SRL', 'cui' => '11111111'],
            confidence: ['name' => 0.95, 'cui' => 0.95],
        );
        $second = new FieldSource(
            documentId: 2,
            documentType: DocumentType::FACTURA,
            values: ['name' => 'Beta SRL', 'cui' => '22222222', 'address' => 'Str. A Betei 3'],
            confidence: ['name' => 0.99, 'cui' => 0.99, 'address' => 0.99],
        );

        $result = $this->aggregateParty([$first, $second]);

        $this->assertCoherentIdentity($result, ['Alfa SRL' => '11111111', 'Beta SRL' => '22222222']);
        // The winner is Alfa (equal authority, lower document id breaks the
        // tie), and Alfa knows no address, so Beta's must not fill the gap.
        if ($result->values['name'] === 'Alfa SRL') {
            self::assertArrayNotHasKey('address', $result->values, 'Beta\'s address cannot attach to Alfa');
        }
    }

    public function testThirdPartyCorrespondenceInThePileNeverContaminatesTheParty(): void
    {
        // The realistic trigger named in the brief: correspondence with an
        // unrelated third party is uploaded alongside the real documents. Its
        // address must never reach the debtor card.
        $contract = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa Construct SRL', 'cui' => '11111111']);
        $thirdParty = $this->party(2, DocumentType::NOTIFICARE, [
            'name' => 'Terta Parte SRL',
            'cui' => '99999999',
            'address' => 'Str. Straina 100',
            'email' => 'terta@example.com',
        ], 0.99);

        $result = $this->aggregateParty([$contract, $thirdParty], ConflictScope::DEBTOR);

        self::assertSame('Alfa Construct SRL', $result->values['name']);
        self::assertSame('11111111', $result->values['cui']);
        self::assertArrayNotHasKey('address', $result->values, 'the third party\'s address must not attach');
        self::assertArrayNotHasKey('email', $result->values, 'nor its email');
    }

    public function testTheChimeraIsBlockedThroughTheWholePrefillService(): void
    {
        // End to end: the same crossing through the public surface the wizard
        // actually calls, on the creditor section.
        $service = $this->serviceWith([
            $this->creditorDocument(1, DocumentType::CONTRACT, ['name' => 'Alfa Construct SRL', 'cui' => '11111111']),
            $this->creditorDocument(2, DocumentType::FACTURA, ['name' => 'Beta Logistic SRL', 'cui' => '22222222']),
        ]);

        $creditor = $service->aggregateForCreditor([1, 2]);

        self::assertSame('Alfa Construct SRL', $creditor->name);
        self::assertSame('11111111', $creditor->cui);
    }

    public function testReversingTheDocumentOrderDoesNotProduceADifferentChimera(): void
    {
        $a = $this->party(1, DocumentType::FACTURA, ['name' => 'Alfa SRL', 'cui' => '11111111', 'email' => 'a@a.ro'], 0.9);
        $b = $this->party(2, DocumentType::FACTURA, ['name' => 'Beta SRL', 'cui' => '22222222', 'email' => 'b@b.ro'], 0.9);

        $forward = $this->aggregateParty([$a, $b]);
        $backward = $this->aggregateParty([$b, $a]);

        self::assertSame($forward->values['name'], $backward->values['name']);
        self::assertSame($forward->values['cui'], $backward->values['cui']);
        $this->assertCoherentIdentity($forward, ['Alfa SRL' => '11111111', 'Beta SRL' => '22222222']);
        // email belongs to the same party as the name, both runs.
        self::assertSame($forward->values['name'] === 'Alfa SRL' ? 'a@a.ro' : 'b@b.ro', $forward->values['email']);
    }

    /**
     * The claim-side of the same problem (decision #5): the invoice number now
     * travels with the sum, so a petition cannot claim one invoice's total
     * while naming another invoice's number.
     */
    public function testTheInvoiceNumberTravelsWithTheSumItBelongsTo(): void
    {
        $invoice = new FieldSource(
            documentId: 1,
            documentType: DocumentType::FACTURA,
            values: ['amount' => 1200.0, 'invoiceNumber' => 'FF-12'],
            confidence: ['amount' => 0.85, 'invoiceNumber' => 0.85],
        );
        // A demand letter restates a different number very confidently.
        $demand = new FieldSource(
            documentId: 2,
            documentType: DocumentType::SOMATIE_ANTERIOARA,
            values: ['amount' => 1200.0, 'invoiceNumber' => 'FF-99'],
            confidence: ['amount' => 0.99, 'invoiceNumber' => 0.99],
        );

        $result = $this->aggregator->aggregate(
            [$invoice, $demand],
            [
                'amount' => FieldGroup::CLAIM_AMOUNT,
                'invoiceNumber' => FieldGroup::CLAIM_AMOUNT,
            ],
            ConflictScope::CLAIM,
        );

        // The invoice is authoritative on the amount group, so both the sum and
        // the number come from it: not the letter's misquoted FF-99.
        self::assertSame('FF-12', $result->values['invoiceNumber']);
        self::assertSame(1, $result->provenance['amount']);
        self::assertSame(1, $result->provenance['invoiceNumber'], 'the number is read from the same document as the sum');
    }

    /**
     * @param AggregatedFields $result
     * @param array<string, string> $validPairs name to its only valid cui
     */
    private function assertCoherentIdentity(AggregatedFields $result, array $validPairs): void
    {
        $name = $result->values['name'] ?? null;
        $cui = $result->values['cui'] ?? null;
        self::assertIsString($name);
        self::assertIsString($cui);
        self::assertArrayHasKey($name, $validPairs, 'the name is one an actual document stated');
        self::assertSame($validPairs[$name], $cui, sprintf('%s must carry its own registration number, never another\'s', $name));
    }

    /**
     * @param list<FieldSource> $sources
     */
    private function aggregateParty(array $sources, ConflictScope $scope = ConflictScope::CREDITOR): AggregatedFields
    {
        $fields = ['personType', 'name', 'cui', 'personalId', 'address', 'county', 'locality', 'email', 'phone', 'iban'];
        $map = FieldGroup::partyFieldMap();
        $groups = [];
        foreach ($fields as $field) {
            $groups[$field] = $map[$field];
        }

        return $this->aggregator->aggregate($sources, $groups, $scope);
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

    /**
     * @param array<string, mixed> $creditor
     */
    private function creditorDocument(int $id, DocumentType $type, array $creditor): Document
    {
        $confidence = [];
        foreach ($creditor as $field => $_) {
            $confidence[$field] = 0.95;
        }
        $document = new Document();
        $document->setDocumentType($type);
        $document->setExtractedData([
            'schemaVersion' => 2,
            'sourceDocumentId' => $id,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'creditor' => $creditor + ['confidencePerField' => $confidence],
        ]);
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }

    /**
     * @param list<Document> $documents
     */
    private function serviceWith(array $documents): PrefillFromExtractionService
    {
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findBy')->willReturn($documents);

        return new PrefillFromExtractionService($repo);
    }
}
