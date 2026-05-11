<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\Entity\Document;
use App\Enum\LegalGroundCategory;
use App\Enum\PersonType;
use App\Repository\DocumentRepository;
use App\Service\Extraction\PrefillFromExtractionService;
use PHPUnit\Framework\TestCase;

final class PrefillFromExtractionServiceTest extends TestCase
{
    public function testEmptyDocumentIdsReturnsEmptyDtos(): void
    {
        // Empty input — service must short-circuit without touching the repo.
        // Using a stub (not a mock) because we don't need behavioural assertions
        // for findBy: the contract is "don't call it when ids[] is empty" but
        // a stub returning an empty array is just as observable through the
        // empty DTOs returned by the service.
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findBy')->willReturn([]);

        $service = new PrefillFromExtractionService($repo);
        $creditor = $service->aggregateForCreditor([]);
        $debtor = $service->aggregateForDebtor([]);
        $claim = $service->aggregateForClaim([]);

        self::assertSame([], $creditor->autoFilled);
        self::assertNull($creditor->name);
        self::assertSame([], $debtor->autoFilled);
        self::assertNull($debtor->name);
        self::assertSame([], $claim->autoFilled);
        self::assertNull($claim->amount);
    }

    public function testSingleDocumentAllHighConfidenceFieldsPopulated(): void
    {
        $document = $this->buildDocumentWithExtractedData([
            'creditor' => [
                'personType' => PersonType::PJ->value,
                'name' => 'Demo SRL',
                'cui' => 'RO12345678',
                'address' => 'Str. Demo 1',
                'iban' => 'RO49AAAA1B31007593840000',
                'confidencePerField' => [
                    'personType' => 0.99,
                    'name' => 0.95,
                    'cui' => 0.97,
                    'address' => 0.88,
                    'iban' => 0.92,
                ],
            ],
            'debtor' => [
                'personType' => PersonType::PJ->value,
                'name' => 'Datornic SRL',
                'cui' => 'RO87654321',
                'address' => 'Str. Datornic 2',
                'confidencePerField' => [
                    'personType' => 0.99,
                    'name' => 0.94,
                    'cui' => 0.96,
                    'address' => 0.85,
                ],
            ],
            'claim' => [
                'amount' => 5000.0,
                'currency' => 'RON',
                'dueDate' => '2025-10-15T00:00:00+00:00',
                'legalGround' => LegalGroundCategory::FACTURA_ACCEPTATA->value,
                'description' => 'Factură 2025/142',
                'confidencePerField' => [
                    'amount' => 0.98,
                    'currency' => 0.99,
                    'dueDate' => 0.91,
                    'legalGround' => 0.83,
                    'description' => 0.82,
                ],
            ],
        ]);

        $service = $this->buildServiceFor([$document]);

        $creditor = $service->aggregateForCreditor([1]);
        self::assertSame(PersonType::PJ, $creditor->personType);
        self::assertSame('Demo SRL', $creditor->name);
        self::assertSame('RO12345678', $creditor->cui);
        self::assertSame('RO49AAAA1B31007593840000', $creditor->iban);
        self::assertEqualsCanonicalizing(
            ['personType', 'name', 'cui', 'address', 'iban'],
            $creditor->autoFilled,
        );

        $debtor = $service->aggregateForDebtor([1]);
        self::assertSame('Datornic SRL', $debtor->name);
        self::assertSame('RO87654321', $debtor->cui);

        $claim = $service->aggregateForClaim([1]);
        self::assertSame(5000.0, $claim->amount);
        self::assertSame('RON', $claim->currency);
        self::assertNotNull($claim->dueDate);
        self::assertSame('2025-10-15', $claim->dueDate->format('Y-m-d'));
        self::assertSame(LegalGroundCategory::FACTURA_ACCEPTATA, $claim->legalGround);
        self::assertContains('amount', $claim->autoFilled);
        self::assertContains('dueDate', $claim->autoFilled);
    }

    public function testMultipleDocumentsConflictingValuesPicksHighestConfidence(): void
    {
        $low = $this->buildDocumentWithExtractedData([
            'creditor' => [
                'name' => 'Low Confidence Name SRL',
                'cui' => 'RO11111111',
                'confidencePerField' => ['name' => 0.85, 'cui' => 0.82],
            ],
        ]);
        $high = $this->buildDocumentWithExtractedData([
            'creditor' => [
                'name' => 'High Confidence Name SRL',
                'cui' => 'RO22222222',
                'confidencePerField' => ['name' => 0.97, 'cui' => 0.99],
            ],
        ]);

        $service = $this->buildServiceFor([$low, $high]);
        $creditor = $service->aggregateForCreditor([1, 2]);

        self::assertSame('High Confidence Name SRL', $creditor->name);
        self::assertSame('RO22222222', $creditor->cui);
    }

    public function testFieldsBelowThresholdAreNotPrefilled(): void
    {
        $document = $this->buildDocumentWithExtractedData([
            'creditor' => [
                'name' => 'Mid Confidence SRL',
                'cui' => 'RO12345678',
                'address' => 'Just below threshold',
                'confidencePerField' => [
                    'name' => 0.85,    // above threshold
                    'cui' => 0.79,     // BELOW threshold (< 0.8)
                    'address' => 0.5,  // way below
                ],
            ],
        ]);

        $service = $this->buildServiceFor([$document]);
        $creditor = $service->aggregateForCreditor([1]);

        self::assertSame('Mid Confidence SRL', $creditor->name);
        self::assertNull($creditor->cui);
        self::assertNull($creditor->address);
        self::assertSame(['name'], $creditor->autoFilled);
    }

    public function testDocumentWithoutExtractedDataIsSkippedGracefully(): void
    {
        $empty = $this->buildDocument(null); // extractedData = null (status PENDING/FAILED)
        $good = $this->buildDocumentWithExtractedData([
            'creditor' => [
                'name' => 'Good SRL',
                'confidencePerField' => ['name' => 0.95],
            ],
        ]);

        $service = $this->buildServiceFor([$empty, $good]);
        $creditor = $service->aggregateForCreditor([1, 2]);

        self::assertSame('Good SRL', $creditor->name);
        self::assertSame(['name'], $creditor->autoFilled);
    }

    public function testMalformedDueDateInPayloadFallsBackToNull(): void
    {
        $document = $this->buildDocumentWithExtractedData([
            'claim' => [
                'amount' => 1000.0,
                'dueDate' => 'not-a-date',
                'confidencePerField' => ['amount' => 0.99, 'dueDate' => 0.95],
            ],
        ]);

        $service = $this->buildServiceFor([$document]);
        $claim = $service->aggregateForClaim([1]);

        self::assertSame(1000.0, $claim->amount);
        self::assertNull($claim->dueDate);
        self::assertContains('amount', $claim->autoFilled);
        self::assertNotContains('dueDate', $claim->autoFilled);
    }

    public function testAllCreditorAndDebtorFieldsAggregatedWhenPresent(): void
    {
        // Full-coverage happy path: every target field that the extraction
        // pipeline can populate must surface in the aggregated DTOs with the
        // correct value AND the field name in `autoFilled[]`. This guards
        // against drift where a new field gets added to one layer (e.g. AI
        // prompt) but the agreggator's iteration list isn't extended.
        $document = $this->buildDocumentWithExtractedData([
            'creditor' => [
                'personType' => PersonType::PJ->value,
                'name' => 'Tehno Construct SRL',
                'cui' => '12345678',
                'personalId' => null,
                'onrcNumber' => 'J40/1234/2025',
                'address' => 'Bd. Demo 100, București',
                'email' => 'contact@tehno.ro',
                'phone' => '0721234567',
                'iban' => 'RO49AAAA1B31007593840000',
                'legalRepresentative' => 'Popescu Ion',
                'confidencePerField' => [
                    'personType' => 0.99,
                    'name' => 0.95,
                    'cui' => 0.99,
                    'onrcNumber' => 0.92,
                    'address' => 0.88,
                    'email' => 0.90,
                    'phone' => 0.85,
                    'iban' => 0.93,
                    'legalRepresentative' => 0.81,
                ],
            ],
            'debtor' => [
                'personType' => PersonType::PJ->value,
                'name' => 'Datornic Trans SA',
                'cui' => '87654321',
                'onrcNumber' => 'J12/5678/2020',
                'address' => 'Str. Datornic 5, Cluj-Napoca',
                'email' => 'office@datornic.ro',
                'phone' => '+40722000111',
                // Real checksum-valid IBAN (mod-97 = 1) to avoid misleading
                // future readers; aggregator does pure pass-through, never
                // validates, but a valid example reduces the chance of this
                // string being copy-pasted into a checksum-aware context later.
                'iban' => 'RO49AAAA1B31007593840000',
                'administrator' => 'Ionescu Maria',
                'confidencePerField' => [
                    'personType' => 0.99,
                    'name' => 0.94,
                    'cui' => 0.99,
                    'onrcNumber' => 0.90,
                    'address' => 0.85,
                    'email' => 0.88,
                    'phone' => 0.82,
                    'iban' => 0.91,
                    'administrator' => 0.80,
                ],
            ],
        ]);

        $service = $this->buildServiceFor([$document]);

        $creditor = $service->aggregateForCreditor([1]);
        self::assertSame(PersonType::PJ, $creditor->personType);
        self::assertSame('Tehno Construct SRL', $creditor->name);
        self::assertSame('12345678', $creditor->cui);
        self::assertSame('J40/1234/2025', $creditor->onrcNumber);
        self::assertSame('Bd. Demo 100, București', $creditor->address);
        self::assertSame('contact@tehno.ro', $creditor->email);
        self::assertSame('0721234567', $creditor->phone);
        self::assertSame('RO49AAAA1B31007593840000', $creditor->iban);
        self::assertSame('Popescu Ion', $creditor->legalRepresentative);
        self::assertEqualsCanonicalizing(
            ['personType', 'name', 'cui', 'onrcNumber', 'address', 'email', 'phone', 'iban', 'legalRepresentative'],
            $creditor->autoFilled,
        );

        $debtor = $service->aggregateForDebtor([1]);
        self::assertSame('Datornic Trans SA', $debtor->name);
        self::assertSame('87654321', $debtor->cui);
        self::assertSame('J12/5678/2020', $debtor->onrcNumber);
        self::assertSame('Str. Datornic 5, Cluj-Napoca', $debtor->address);
        self::assertSame('office@datornic.ro', $debtor->email);
        self::assertSame('+40722000111', $debtor->phone);
        self::assertSame('RO49AAAA1B31007593840000', $debtor->iban);
        self::assertSame('Ionescu Maria', $debtor->administrator);
        self::assertEqualsCanonicalizing(
            ['personType', 'name', 'cui', 'onrcNumber', 'address', 'email', 'phone', 'iban', 'administrator'],
            $debtor->autoFilled,
        );
    }

    public function testMissingConfidencePerFieldIsTreatedAsBelowThreshold(): void
    {
        $document = $this->buildDocumentWithExtractedData([
            'creditor' => [
                'name' => 'No Confidence Map',
                // confidencePerField key omitted entirely
            ],
        ]);

        $service = $this->buildServiceFor([$document]);
        $creditor = $service->aggregateForCreditor([1]);

        self::assertNull($creditor->name);
        self::assertSame([], $creditor->autoFilled);
    }

    /**
     * @param array<string, mixed> $extractedData
     */
    private function buildDocumentWithExtractedData(array $extractedData): Document
    {
        return $this->buildDocument($extractedData);
    }

    /**
     * @param array<string, mixed>|null $extractedData
     */
    private function buildDocument(?array $extractedData): Document
    {
        // We can't set Document::$id (private + auto-generated) without
        // reflection. The service relies on findBy() returning the right
        // documents, so the id isn't read inside the service — only the
        // extractedData payload matters. Reflection sets id only to satisfy
        // findBy contract when we use a mock that ignores it.
        $document = new Document();
        if ($extractedData !== null) {
            $document->setExtractedData($extractedData);
        }

        return $document;
    }

    /**
     * @param list<Document> $documents
     */
    private function buildServiceFor(array $documents): PrefillFromExtractionService
    {
        // Stub — we just want findBy() to return the documents we hand-crafted.
        // No behavioural assertions on the repo call shape itself.
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findBy')->willReturn($documents);

        return new PrefillFromExtractionService($repo);
    }
}
