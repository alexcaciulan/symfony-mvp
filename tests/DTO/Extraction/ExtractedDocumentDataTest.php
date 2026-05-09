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

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line — intentional readonly violation
        $dto->strategy = 'mutated';
    }
}
