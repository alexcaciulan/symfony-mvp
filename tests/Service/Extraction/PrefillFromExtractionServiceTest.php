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
                'county' => 'București',
                'locality' => 'Sector 1',
                'email' => 'contact@tehno.ro',
                'phone' => '0721234567',
                'iban' => 'RO49AAAA1B31007593840000',
                'legalRepresentative' => 'Popescu Ion',
                'bankName' => 'Banca Transilvania',
                'confidencePerField' => [
                    'personType' => 0.99,
                    'name' => 0.95,
                    'cui' => 0.99,
                    'onrcNumber' => 0.92,
                    'address' => 0.88,
                    'county' => 0.91,
                    'locality' => 0.89,
                    'email' => 0.90,
                    'phone' => 0.85,
                    'iban' => 0.93,
                    'legalRepresentative' => 0.81,
                    'bankName' => 0.90,
                ],
            ],
            'debtor' => [
                'personType' => PersonType::PJ->value,
                'name' => 'Datornic Trans SA',
                'cui' => '87654321',
                'onrcNumber' => 'J12/5678/2020',
                'address' => 'Str. Datornic 5, Cluj-Napoca',
                'county' => 'Cluj',
                'locality' => 'Cluj-Napoca',
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
                    'county' => 0.93,
                    'locality' => 0.90,
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
        // county/locality select the stamp-duty town hall (OUG 80/2013 art. 40
        // alin. 1) and reach the form as addressCounty/addressLocality.
        self::assertSame('București', $creditor->addressCounty);
        self::assertSame('Sector 1', $creditor->addressLocality);
        self::assertSame('contact@tehno.ro', $creditor->email);
        self::assertSame('0721234567', $creditor->phone);
        self::assertSame('RO49AAAA1B31007593840000', $creditor->iban);
        self::assertSame('Popescu Ion', $creditor->legalRepresentative);
        self::assertSame('Banca Transilvania', $creditor->bankName);
        self::assertEqualsCanonicalizing(
            ['personType', 'name', 'cui', 'onrcNumber', 'address', 'addressCounty', 'addressLocality', 'email', 'phone', 'iban', 'legalRepresentative', 'bankName'],
            $creditor->autoFilled,
        );

        $debtor = $service->aggregateForDebtor([1]);
        self::assertSame('Datornic Trans SA', $debtor->name);
        self::assertSame('87654321', $debtor->cui);
        self::assertSame('J12/5678/2020', $debtor->onrcNumber);
        self::assertSame('Str. Datornic 5, Cluj-Napoca', $debtor->address);
        self::assertSame('Cluj', $debtor->addressCounty);
        self::assertSame('Cluj-Napoca', $debtor->addressLocality);
        self::assertSame('office@datornic.ro', $debtor->email);
        self::assertSame('+40722000111', $debtor->phone);
        self::assertSame('RO49AAAA1B31007593840000', $debtor->iban);
        self::assertSame('Ionescu Maria', $debtor->administrator);
        self::assertEqualsCanonicalizing(
            ['personType', 'name', 'cui', 'onrcNumber', 'address', 'addressCounty', 'addressLocality', 'email', 'phone', 'iban', 'administrator'],
            $debtor->autoFilled,
        );
    }

    public function testAllClaimFieldsAggregatedWhenPresent(): void
    {
        // Same drift guard as the creditor/debtor full-coverage test, for the
        // claim block extended with invoice/contract/penalty metadata.
        $document = $this->buildDocumentWithExtractedData([
            'claim' => [
                'amount' => 12000.0,
                'currency' => 'RON',
                'dueDate' => '2026-02-01T00:00:00+00:00',
                'legalGround' => LegalGroundCategory::FACTURA_ACCEPTATA->value,
                'description' => 'Servicii consultanță Q4 2025',
                'invoiceNumber' => 'MJ 2026-00042',
                'invoiceDate' => '2026-01-10T00:00:00+00:00',
                'contractNumber' => '45/2025',
                'contractDate' => '2025-12-01T00:00:00+00:00',
                'contractReference' => 'contract de prestări servicii',
                'penaltyType' => \App\Enum\PenaltyType::CONTRACTUAL->value,
                'contractualPenaltyRate' => 0.1,
                'confidencePerField' => [
                    'amount' => 0.99,
                    'currency' => 0.99,
                    'dueDate' => 0.91,
                    'legalGround' => 0.83,
                    'description' => 0.82,
                    'invoiceNumber' => 0.95,
                    'invoiceDate' => 0.90,
                    'contractNumber' => 0.93,
                    'contractDate' => 0.88,
                    'contractReference' => 0.85,
                    'penaltyType' => 0.92,
                    'contractualPenaltyRate' => 0.90,
                ],
            ],
        ]);

        $service = $this->buildServiceFor([$document]);
        $claim = $service->aggregateForClaim([1]);

        self::assertSame(12000.0, $claim->amount);
        self::assertSame('MJ 2026-00042', $claim->invoiceNumber);
        self::assertNotNull($claim->invoiceDate);
        self::assertSame('2026-01-10', $claim->invoiceDate->format('Y-m-d'));
        self::assertSame('45/2025', $claim->contractNumber);
        self::assertNotNull($claim->contractDate);
        self::assertSame('2025-12-01', $claim->contractDate->format('Y-m-d'));
        self::assertSame('contract de prestări servicii', $claim->contractReference);
        self::assertSame(\App\Enum\PenaltyType::CONTRACTUAL, $claim->penaltyType);
        self::assertSame(0.1, $claim->contractualPenaltyRate);
        self::assertEqualsCanonicalizing(
            ['amount', 'currency', 'dueDate', 'legalGround', 'description', 'invoiceNumber', 'invoiceDate', 'contractNumber', 'contractDate', 'contractReference', 'penaltyType', 'contractualPenaltyRate'],
            $claim->autoFilled,
        );
    }

    public function testContractualPenaltyTypeWithoutRateFallsBackToDefault(): void
    {
        // CONTRACTUAL prefilled but no rate → Step3 would be invalid (Assert\When
        // requires the rate). The service drops both and falls back to the
        // wizard default (LEGAL_PENALIZATOARE), without marking them auto-filled.
        $document = $this->buildDocumentWithExtractedData([
            'claim' => [
                'amount' => 5000.0,
                'penaltyType' => \App\Enum\PenaltyType::CONTRACTUAL->value,
                // contractualPenaltyRate absent / below threshold
                'confidencePerField' => ['amount' => 0.99, 'penaltyType' => 0.95],
            ],
        ]);

        $service = $this->buildServiceFor([$document]);
        $claim = $service->aggregateForClaim([1]);

        self::assertSame(\App\Enum\PenaltyType::LEGAL_PENALIZATOARE, $claim->penaltyType);
        self::assertNull($claim->contractualPenaltyRate);
        self::assertNotContains('penaltyType', $claim->autoFilled);
        self::assertNotContains('contractualPenaltyRate', $claim->autoFilled);
    }

    public function testMalformedInvoiceAndContractDatesFallBackToNull(): void
    {
        $document = $this->buildDocumentWithExtractedData([
            'claim' => [
                'amount' => 1000.0,
                'invoiceDate' => 'not-a-date',
                'contractDate' => '31/12/2025',
                'confidencePerField' => ['amount' => 0.99, 'invoiceDate' => 0.95, 'contractDate' => 0.93],
            ],
        ]);

        $service = $this->buildServiceFor([$document]);
        $claim = $service->aggregateForClaim([1]);

        self::assertNull($claim->invoiceDate);
        self::assertNull($claim->contractDate);
        self::assertNotContains('invoiceDate', $claim->autoFilled);
        self::assertNotContains('contractDate', $claim->autoFilled);
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
     * A partial confidence map is the dangerous case: the strategy returns a
     * value, the map simply omits its score, and the field vanishes with no
     * error anywhere. This is what left `addressCounty` null on real invoices
     * (the AI Vision confidencePerField example listed no county/locality, so
     * the model scored neither), which surfaced to the lawyer as "Instanță
     * nedeterminată". Pinned here so the prompt-to-gate contract is explicit:
     * every field the prompt asks for must also appear in its scoring example.
     */
    public function testExtractedValuesWithoutTheirConfidenceEntryAreDropped(): void
    {
        $document = $this->buildDocumentWithExtractedData([
            'debtor' => [
                'name' => 'Datornic Trans SA',
                'county' => 'Ilfov',
                'locality' => 'București',
                // Map present, but scores only `name`.
                'confidencePerField' => ['name' => 0.95],
            ],
        ]);

        $service = $this->buildServiceFor([$document]);
        $debtor = $service->aggregateForDebtor([1]);

        self::assertSame('Datornic Trans SA', $debtor->name);
        self::assertNull($debtor->addressCounty);
        self::assertNull($debtor->addressLocality);
        self::assertSame(['name'], $debtor->autoFilled);
    }

    public function testAllConfidencesBelowThresholdReturnsEmptyDtos(): void
    {
        // A single document with all per-field confidences just below the
        // 0.8 cutoff: every value is "known" but none is trusted enough to
        // prefill. The DTOs must come back structurally valid (no nulls
        // escaping into required positions) and `autoFilled` empty.
        $document = $this->buildDocumentWithExtractedData([
            'creditor' => [
                'personType' => PersonType::PJ->value,
                'name' => 'Below Threshold SRL',
                'cui' => 'RO12345678',
                'confidencePerField' => [
                    'personType' => 0.79,
                    'name' => 0.5,
                    'cui' => 0.6,
                ],
            ],
            'debtor' => [
                'name' => 'Below Threshold Debtor',
                'confidencePerField' => ['name' => 0.7],
            ],
            'claim' => [
                'amount' => 1500,
                'confidencePerField' => ['amount' => 0.79],
            ],
        ]);

        $service = $this->buildServiceFor([$document]);

        $creditor = $service->aggregateForCreditor([1]);
        $debtors = $service->aggregateForDebtors([1]);
        $claim = $service->aggregateForClaim([1]);

        self::assertSame([], $creditor->autoFilled);
        self::assertNull($creditor->name);
        self::assertSame([], $debtors->debtors[0]->autoFilled);
        self::assertNull($debtors->debtors[0]->name);
        self::assertSame([], $claim->autoFilled);
        self::assertNull($claim->amount);
    }

    public function testAggregateForDebtorsAlwaysReturnsAtLeastOneEntry(): void
    {
        // Even with no documents at all, the Step2DebtorsData contract is
        // "minimum one entry so the form can render the primary debtor card
        // immediately"; the entry is empty but structurally present so the
        // Symfony Validator `Count(min: 1)` constraint at submit time only
        // triggers when the user explicitly removed all entries.
        $service = $this->buildServiceFor([]);

        $debtors = $service->aggregateForDebtors([]);

        self::assertCount(1, $debtors->debtors);
        self::assertNull($debtors->debtors[0]->name);
        self::assertSame([], $debtors->debtors[0]->autoFilled);
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
