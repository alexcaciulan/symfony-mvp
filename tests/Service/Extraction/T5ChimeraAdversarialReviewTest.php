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
 * Adversarial review of the anti-chimera guard (D6) and gap-filling (scenario 6).
 *
 * The invariant under test: whatever the aggregation returns, every field of a
 * party must have come from a document about that party. A name from one
 * company and a registration number, address, or bank account from another is
 * the defect the whole aggregation layer exists to prevent, and the address in
 * particular decides the competent court (CPC art. 99).
 */
final class T5ChimeraAdversarialReviewTest extends TestCase
{
    private CoherentAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new CoherentAggregator();
    }

    // ---------- scenario 1: the central chimera ----------

    public function testContractAAndInvoiceBNeverCrossInEitherDirection(): void
    {
        $contract = $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa SRL', 'cui' => '11111111']);
        $invoice = $this->party(2, DocumentType::FACTURA, ['name' => 'Beta SRL', 'cui' => '22222222'], 0.99);

        $result = $this->aggregateParty([$contract, $invoice]);

        // Only two coherent outcomes exist; the crosses A+222 and B+111 are the failures.
        $this->assertCoherent($result, ['Alfa SRL' => '11111111', 'Beta SRL' => '22222222']);
    }

    public function testAThreeDocumentPileKeepsIdentityCoherent(): void
    {
        // Correspondence with two unrelated third parties in the pile alongside
        // the real debtor. Whoever wins identity, no other party's number attaches.
        $sources = [
            $this->party(1, DocumentType::CONTRACT, ['name' => 'Alfa SRL', 'cui' => '11111111']),
            $this->party(2, DocumentType::FACTURA, ['name' => 'Beta SRL', 'cui' => '22222222'], 0.99),
            $this->party(3, DocumentType::NOTIFICARE, ['name' => 'Gama SRL', 'cui' => '33333333'], 0.99),
        ];

        $result = $this->aggregateParty($sources, ConflictScope::DEBTOR);

        $this->assertCoherent($result, [
            'Alfa SRL' => '11111111',
            'Beta SRL' => '22222222',
            'Gama SRL' => '33333333',
        ]);
        // The contract owns identity: highest authority on PARTY_IDENTITY.
        self::assertSame('Alfa SRL', $result->values['name']);
    }

    public function testAWinnerWithNoCuiDoesNotAcquireADifferentEntitysCui(): void
    {
        // The identity winner (contract) names the party but carries no trusted
        // CUI. A different entity's invoice must not fill that gap: an empty CUI
        // is safe, a stranger's CUI is a filing against the wrong legal person.
        $contract = new FieldSource(
            documentId: 1,
            documentType: DocumentType::CONTRACT,
            values: ['name' => 'Alfa Distributie SRL', 'cui' => '11111111'],
            confidence: ['name' => 0.95, 'cui' => 0.40],
        );
        $invoice = new FieldSource(
            documentId: 2,
            documentType: DocumentType::FACTURA,
            values: ['name' => 'Beta Logistica SRL', 'cui' => '22222222'],
            confidence: ['name' => 0.99, 'cui' => 0.99],
        );

        $result = $this->aggregateParty([$contract, $invoice]);

        self::assertSame('Alfa Distributie SRL', $result->values['name']);
        self::assertArrayNotHasKey('cui', $result->values, 'a stranger\'s CUI must never fill the winner\'s gap');
    }

    // ---------- scenario 1 / regression: the untrusted-name leak ----------

    /**
     * A third-party document whose name was read below the confidence bar, and
     * which carries no registration number, still contaminates the debtor's
     * contact block: its address (which selects the competent court under CPC
     * art. 99) and its email attach to a different, anchored party.
     *
     * The guard treats "name present but untrusted" the same as "no name at
     * all", so it lets the stranger through as if it said nothing identifying.
     * A blurry notice to a third party in the upload pile is exactly the
     * realistic trigger the layer was built to stop.
     *
     * This test asserts the correct behaviour and currently FAILS, documenting
     * the defect.
     */
    public function testAThirdPartyWithAnUntrustedNameDoesNotLeakItsAddress(): void
    {
        $debtor = new FieldSource(
            documentId: 1,
            documentType: DocumentType::CONTRACT,
            values: ['name' => 'Alfa Construct SRL', 'cui' => '11111111'],
            confidence: ['name' => 0.95, 'cui' => 0.95],
        );
        $stranger = new FieldSource(
            documentId: 2,
            documentType: DocumentType::NOTIFICARE,
            // Name present but under MIN_CONFIDENCE; no CUI; address and email trusted.
            values: ['name' => 'Terta Straina SRL', 'address' => 'Str. Straina 100', 'email' => 'terta@example.com'],
            confidence: ['name' => 0.60, 'address' => 0.95, 'email' => 0.95],
        );

        $result = $this->aggregateParty([$debtor, $stranger], ConflictScope::DEBTOR);

        self::assertSame('Alfa Construct SRL', $result->values['name']);
        self::assertSame('11111111', $result->values['cui']);
        self::assertArrayNotHasKey(
            'address',
            $result->values,
            'a third party\'s address must not become the debtor\'s, it selects the court',
        );
        self::assertArrayNotHasKey('email', $result->values, 'nor its email');
    }

    /**
     * The same leak through the public service, on the creditor section, which
     * has no clustering step to shield it: the creditor card ends up carrying
     * one company's name and registration number with a different company's
     * address, county and email. The county selects the stamp-duty town hall,
     * so the contamination reaches the filing.
     *
     * Asserts the correct behaviour and currently FAILS.
     */
    public function testAStrangerWithAnUntrustedNameDoesNotContaminateTheCreditorCard(): void
    {
        $service = $this->serviceWith([
            $this->creditorDocument(
                1,
                DocumentType::CONTRACT,
                ['name' => 'Alfa Construct SRL', 'cui' => '11111111'],
                ['name' => 0.95, 'cui' => 0.95],
            ),
            $this->creditorDocument(
                2,
                DocumentType::NOTIFICARE,
                ['name' => 'Terta Straina SRL', 'address' => 'Str. Straina 100', 'county' => 'Ilfov', 'email' => 'terta@example.com'],
                ['name' => 0.60, 'address' => 0.95, 'county' => 0.95, 'email' => 0.95],
            ),
        ]);

        $creditor = $service->aggregateForCreditor([1, 2]);

        self::assertSame('Alfa Construct SRL', $creditor->name);
        self::assertSame('11111111', $creditor->cui);
        self::assertNull($creditor->address, 'a stranger\'s address must not reach the creditor card');
        self::assertNull($creditor->addressCounty, 'the county selects the stamp-duty town hall');
        self::assertNull($creditor->email);
    }

    /**
     * The debtor path survives the same pile, because clustering compares raw
     * names and splits the stranger into a card of its own before the
     * aggregation ever sees it as a donor. The asymmetry is what makes the two
     * failures above a gap in the guard rather than a deliberate policy.
     */
    public function testTheDebtorPathIsShieldedByClusteringInTheSameScenario(): void
    {
        $service = $this->serviceWith([
            $this->debtorDocumentScored(
                1,
                DocumentType::CONTRACT,
                ['personType' => 'PJ', 'name' => 'Alfa Construct SRL', 'cui' => '11111111'],
                ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95],
            ),
            $this->debtorDocumentScored(
                2,
                DocumentType::NOTIFICARE,
                ['name' => 'Terta Straina SRL', 'address' => 'Str. Straina 100'],
                ['name' => 0.60, 'address' => 0.95],
            ),
        ]);

        $debtors = $service->aggregateForDebtors([1, 2])->debtors;
        $alfa = $this->debtorNamed($debtors, 'Alfa Construct SRL');

        self::assertNotNull($alfa);
        self::assertNull($alfa->address, 'the stranger clustered separately, so nothing leaked');
    }

    /**
     * The counterpart that already works: raise the same stranger's name above
     * the confidence bar and the guard catches it. The pair localises the
     * defect to the confidence gate on the name comparison.
     */
    public function testTheSameStrangerIsBlockedOnceItsNameIsTrusted(): void
    {
        $debtor = new FieldSource(
            documentId: 1,
            documentType: DocumentType::CONTRACT,
            values: ['name' => 'Alfa Construct SRL', 'cui' => '11111111'],
            confidence: ['name' => 0.95, 'cui' => 0.95],
        );
        $stranger = new FieldSource(
            documentId: 2,
            documentType: DocumentType::NOTIFICARE,
            values: ['name' => 'Terta Straina SRL', 'address' => 'Str. Straina 100', 'email' => 'terta@example.com'],
            confidence: ['name' => 0.95, 'address' => 0.95, 'email' => 0.95],
        );

        $result = $this->aggregateParty([$debtor, $stranger], ConflictScope::DEBTOR);

        self::assertArrayNotHasKey('address', $result->values);
        self::assertArrayNotHasKey('email', $result->values);
    }

    // ---------- scenario 6: gap-filling only within one party ----------

    public function testTheEmailIsFilledFromAnotherDocumentWithTheSameCui(): void
    {
        // Winner has the name but no email; a second document with the same CUI
        // supplies it. The two are one party, so the gap is filled.
        $service = $this->serviceWith([
            $this->debtorDocument(1, DocumentType::CONTRACT, ['name' => 'Alfa SRL', 'cui' => '11111111']),
            $this->debtorDocument(2, DocumentType::FACTURA, ['name' => 'Alfa SRL', 'cui' => '11111111', 'email' => 'office@alfa.ro']),
        ]);

        $debtor = $service->aggregateForDebtors([1, 2])->debtors[0];

        self::assertSame('Alfa SRL', $debtor->name);
        self::assertSame('11111111', $debtor->cui);
        self::assertSame('office@alfa.ro', $debtor->email);
    }

    public function testTheEmailIsNotFilledFromADocumentWithADifferentCui(): void
    {
        // Same shape, but the second document is a different legal person. Its
        // email must not attach, even though the winner's email is empty.
        $service = $this->serviceWith([
            $this->debtorDocument(1, DocumentType::CONTRACT, ['name' => 'Alfa SRL', 'cui' => '11111111']),
            $this->debtorDocument(2, DocumentType::FACTURA, ['name' => 'Beta SRL', 'cui' => '22222222', 'email' => 'office@beta.ro']),
        ]);

        // Two different CUIs are two debtors; the first card is Alfa and must carry no Beta data.
        $debtors = $service->aggregateForDebtors([1, 2])->debtors;
        $alfa = $this->debtorNamed($debtors, 'Alfa SRL');

        self::assertNotNull($alfa);
        self::assertNull($alfa->email, 'a different legal person\'s email must not fill the gap');
    }

    // ---------- helpers ----------

    /**
     * @param AggregatedFields $result
     * @param array<string, string> $validPairs name to its only valid cui
     */
    private function assertCoherent(AggregatedFields $result, array $validPairs): void
    {
        $name = $result->values['name'] ?? null;
        $cui = $result->values['cui'] ?? null;
        self::assertIsString($name);
        self::assertIsString($cui);
        self::assertArrayHasKey($name, $validPairs);
        self::assertSame(
            $validPairs[$name],
            $cui,
            sprintf('%s must carry its own registration number, never another\'s', $name),
        );
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
     * @param array<string, mixed> $debtor
     */
    private function debtorDocument(int $id, DocumentType $type, array $debtor): Document
    {
        $confidence = [];
        foreach ($debtor as $field => $_) {
            $confidence[$field] = 0.95;
        }
        $document = new Document();
        $document->setDocumentType($type);
        $document->setExtractedData([
            'schemaVersion' => 2,
            'sourceDocumentId' => $id,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'debtors' => [$debtor + ['confidencePerField' => $confidence]],
        ]);
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }

    /**
     * @param array<string, mixed> $creditor
     * @param array<string, float> $confidence
     */
    private function creditorDocument(int $id, DocumentType $type, array $creditor, array $confidence): Document
    {
        return $this->payloadDocument($id, $type, ['creditor' => $creditor + ['confidencePerField' => $confidence]]);
    }

    /**
     * @param array<string, mixed> $debtor
     * @param array<string, float> $confidence
     */
    private function debtorDocumentScored(int $id, DocumentType $type, array $debtor, array $confidence): Document
    {
        return $this->payloadDocument($id, $type, ['debtors' => [$debtor + ['confidencePerField' => $confidence]]]);
    }

    /**
     * @param array<string, mixed> $sections
     */
    private function payloadDocument(int $id, DocumentType $type, array $sections): Document
    {
        $document = new Document();
        $document->setDocumentType($type);
        $document->setExtractedData([
            'schemaVersion' => 2,
            'sourceDocumentId' => $id,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
        ] + $sections);
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }

    /**
     * @param list<\App\DTO\Wizard\Step2DebtorEntry> $debtors
     */
    private function debtorNamed(array $debtors, string $name): ?\App\DTO\Wizard\Step2DebtorEntry
    {
        foreach ($debtors as $debtor) {
            if ($debtor->name === $name) {
                return $debtor;
            }
        }

        return null;
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
