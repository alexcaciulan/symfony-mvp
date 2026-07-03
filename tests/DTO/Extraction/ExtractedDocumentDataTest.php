<?php

namespace App\Tests\DTO\Extraction;

use App\DTO\Extraction\ClaimExtraction;
use App\DTO\Extraction\CreditorExtraction;
use App\DTO\Extraction\DebtorExtraction;
use App\DTO\Extraction\ExtractedDocumentData;
use App\Enum\LegalGroundCategory;
use App\Enum\PersonType;
use PHPUnit\Framework\TestCase;

class ExtractedDocumentDataTest extends TestCase
{
    public function testCanConstructWithMinimalArgs(): void
    {
        $extractedAt = new \DateTimeImmutable('2026-05-09 10:00:00');
        $dto = new ExtractedDocumentData(
            sourceDocumentId: 42,
            strategy: 'stub',
            globalConfidence: 0.0,
            extractedAt: $extractedAt,
        );

        $this->assertSame(42, $dto->sourceDocumentId);
        $this->assertSame('stub', $dto->strategy);
        $this->assertSame(0.0, $dto->globalConfidence);
        $this->assertSame($extractedAt, $dto->extractedAt);
        $this->assertNull($dto->creditor);
        $this->assertNull($dto->debtor);
        $this->assertNull($dto->claim);
        $this->assertNull($dto->rawOcrText);
    }

    public function testCanConstructWithAllSubDtos(): void
    {
        $dto = new ExtractedDocumentData(
            sourceDocumentId: 1,
            strategy: 'pdf_parser',
            globalConfidence: 0.85,
            extractedAt: new \DateTimeImmutable('2026-05-09 12:00:00'),
            creditor: new CreditorExtraction(personType: PersonType::PJ, name: 'SC Foo SRL', cui: 'RO12345678'),
            debtor: new DebtorExtraction(personType: PersonType::PJ, name: 'SC Bar SRL', cui: 'RO87654321'),
            claim: new ClaimExtraction(amount: 5000.0, currency: 'RON', legalGround: LegalGroundCategory::FACTURA_ACCEPTATA),
            rawOcrText: 'sample text',
        );

        $this->assertNotNull($dto->creditor);
        $this->assertSame('SC Foo SRL', $dto->creditor->name);
        $this->assertNotNull($dto->debtor);
        $this->assertSame('SC Bar SRL', $dto->debtor->name);
        $this->assertNotNull($dto->claim);
        $this->assertSame(5000.0, $dto->claim->amount);
        $this->assertSame(LegalGroundCategory::FACTURA_ACCEPTATA, $dto->claim->legalGround);
        $this->assertSame('sample text', $dto->rawOcrText);
    }

    public function testToArrayReturnsJsonFriendlyShape(): void
    {
        $extractedAt = new \DateTimeImmutable('2026-05-09 10:00:00+00:00');
        $dueDate = new \DateTimeImmutable('2026-06-01 00:00:00+00:00');
        $dto = new ExtractedDocumentData(
            sourceDocumentId: 7,
            strategy: 'pdf_parser',
            globalConfidence: 0.95,
            extractedAt: $extractedAt,
            creditor: new CreditorExtraction(
                personType: PersonType::PJ,
                name: 'SC Creditor SRL',
                cui: 'RO12345',
                iban: 'RO49AAAA1B31007593840000',
                confidencePerField: ['name' => 0.95, 'cui' => 0.99],
            ),
            debtor: new DebtorExtraction(
                personType: PersonType::PF,
                name: 'Ion Popescu',
                personalId: '1234567890123',
            ),
            claim: new ClaimExtraction(
                amount: 5000.0,
                currency: 'RON',
                dueDate: $dueDate,
                legalGround: LegalGroundCategory::CONTRACT_PRESTARI_SERVICII,
            ),
        );

        $array = $dto->toArray();

        $this->assertSame(7, $array['sourceDocumentId']);
        $this->assertSame('pdf_parser', $array['strategy']);
        $this->assertSame(0.95, $array['globalConfidence']);
        $this->assertSame($extractedAt->format(\DateTimeInterface::ATOM), $array['extractedAt']);

        $this->assertSame('PJ', $array['creditor']['personType']);
        $this->assertSame('SC Creditor SRL', $array['creditor']['name']);
        $this->assertSame(['name' => 0.95, 'cui' => 0.99], $array['creditor']['confidencePerField']);

        $this->assertSame('PF', $array['debtor']['personType']);
        $this->assertSame('Ion Popescu', $array['debtor']['name']);

        $this->assertSame(5000.0, $array['claim']['amount']);
        $this->assertSame($dueDate->format(\DateTimeInterface::ATOM), $array['claim']['dueDate']);
        $this->assertSame('CONTRACT_PRESTARI_SERVICII', $array['claim']['legalGround']);

        // Verify shape is JSON-serializable round-trip (preserve `.0` on floats
        // so that `5000.0` doesn't degrade to int `5000` after decode)
        $json = json_encode($array, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $this->assertIsString($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($array, $decoded);
    }

    public function testToArraySerializesNewClaimCreditorFieldsAndDebtorGeo(): void
    {
        $invoiceDate = new \DateTimeImmutable('2026-01-10 00:00:00+00:00');
        $contractDate = new \DateTimeImmutable('2025-12-01 00:00:00+00:00');
        $dto = new ExtractedDocumentData(
            sourceDocumentId: 9,
            strategy: 'ai_vision',
            globalConfidence: 0.9,
            extractedAt: new \DateTimeImmutable('2026-05-09 10:00:00+00:00'),
            creditor: new CreditorExtraction(
                name: 'SC Creditor SRL',
                bankName: 'Banca Transilvania',
            ),
            debtor: new DebtorExtraction(
                name: 'SC Debtor SRL',
                county: 'Cluj',
                locality: 'Cluj-Napoca',
            ),
            claim: new ClaimExtraction(
                amount: 12000.0,
                invoiceNumber: 'MJ 2026-00042',
                invoiceDate: $invoiceDate,
                contractNumber: '45/2025',
                contractDate: $contractDate,
                contractReference: 'contract de prestări servicii',
                penaltyType: \App\Enum\PenaltyType::CONTRACTUAL,
                contractualPenaltyRate: 0.1,
            ),
        );

        $array = $dto->toArray();

        // Creditor bankName.
        $this->assertSame('Banca Transilvania', $array['creditor']['bankName']);
        // Debtor county/locality (previously a latent serialization gap).
        $this->assertSame('Cluj', $array['debtor']['county']);
        $this->assertSame('Cluj-Napoca', $array['debtor']['locality']);
        // Claim invoice/contract/penalty metadata.
        $this->assertSame('MJ 2026-00042', $array['claim']['invoiceNumber']);
        $this->assertSame($invoiceDate->format(\DateTimeInterface::ATOM), $array['claim']['invoiceDate']);
        $this->assertSame('45/2025', $array['claim']['contractNumber']);
        $this->assertSame($contractDate->format(\DateTimeInterface::ATOM), $array['claim']['contractDate']);
        $this->assertSame('contract de prestări servicii', $array['claim']['contractReference']);
        $this->assertSame('CONTRACTUAL', $array['claim']['penaltyType']);
        $this->assertSame(0.1, $array['claim']['contractualPenaltyRate']);

        // Null penalty/date fields serialize as null, not absent keys.
        $emptyClaim = new ExtractedDocumentData(
            sourceDocumentId: 10,
            strategy: 'ai_vision',
            globalConfidence: 0.0,
            extractedAt: new \DateTimeImmutable('2026-05-09 10:00:00+00:00'),
            claim: new ClaimExtraction(amount: 1.0),
        );
        $emptyArray = $emptyClaim->toArray();
        $this->assertNull($emptyArray['claim']['invoiceDate']);
        $this->assertNull($emptyArray['claim']['penaltyType']);
        $this->assertNull($emptyArray['claim']['contractualPenaltyRate']);
    }

    public function testToArrayHandlesAllNullSubDtos(): void
    {
        $dto = new ExtractedDocumentData(
            sourceDocumentId: 1,
            strategy: 'stub',
            globalConfidence: 0.0,
            extractedAt: new \DateTimeImmutable(),
        );

        $array = $dto->toArray();

        $this->assertNull($array['creditor']);
        $this->assertNull($array['debtor']);
        $this->assertNull($array['claim']);
        $this->assertNull($array['rawOcrText']);
    }

    public function testReadonlyPreventsModification(): void
    {
        $dto = new ExtractedDocumentData(
            sourceDocumentId: 1,
            strategy: 'stub',
            globalConfidence: 0.0,
            extractedAt: new \DateTimeImmutable(),
        );

        $caught = null;
        try {
            // Intentional readonly violation — the whole point of this test is to
            // prove PHP throws when the property is mutated post-construction.
            // Suppress static-analysis noise from both PHPStan and PhpStorm.
            // @phpstan-ignore-next-line
            /** @noinspection PhpReadonlyPropertyWrittenOutsideOfScopeInspection */
            $dto->strategy = 'mutated';
        } catch (\Error $e) {
            $caught = $e;
        }

        // Strict checks instead of expectException — `expectException(\Error)`
        // would also pass if `strategy` were renamed/removed (PHP throws on
        // dynamic-property creation against a readonly class). We need to
        // prove that the property exists AND is locked AND the original value
        // survived the failed assignment.
        $this->assertNotNull($caught, 'Assignment to readonly property must throw');
        $this->assertStringContainsString(
            'readonly',
            $caught->getMessage(),
            'Error must be about readonly enforcement, not about an unrelated dynamic-property failure',
        );
        $this->assertSame('stub', $dto->strategy, 'Original value must survive the failed assignment');
    }
}
