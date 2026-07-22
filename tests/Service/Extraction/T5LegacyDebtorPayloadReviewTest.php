<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Repository\DocumentRepository;
use App\Service\Extraction\PrefillFromExtractionService;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial review of backward compatibility (scenario 3).
 *
 * Payloads written before a document could name more than one debtor carry a
 * single `debtor` object. They are never migrated: the payload is evidence of
 * what was extracted at the time, and rewriting it would make a case file
 * disagree with the audit trail describing it. So the reader must normalise,
 * and an old payload must prefill exactly as the new shape does.
 *
 * Every fixture here is hand-built JSON, decoded into the payload array. None
 * of it goes through the writer under test, so the test cannot pass merely
 * because the new code round-trips its own output.
 */
final class T5LegacyDebtorPayloadReviewTest extends TestCase
{
    public function testALegacySingularDebtorPrefillsIdenticallyToTheList(): void
    {
        $legacy = $this->decode($this->legacyJson());
        $modern = $this->decode($this->modernJson());

        $fromLegacy = $this->serviceFor([$this->documentWith(1, $legacy)])->aggregate([1]);
        $fromModern = $this->serviceFor([$this->documentWith(1, $modern)])->aggregate([1]);

        self::assertEquals($fromModern->debtors, $fromLegacy->debtors);
        self::assertEquals($fromModern->creditor, $fromLegacy->creditor);
        self::assertEquals($fromModern->claim, $fromLegacy->claim);
        self::assertEquals($fromModern->provenance, $fromLegacy->provenance);
    }

    public function testTheLegacyPayloadActuallyCarriesTheExpectedValues(): void
    {
        // Guards the test above from passing because both sides prefill nothing.
        $result = $this->serviceFor([$this->documentWith(1, $this->decode($this->legacyJson()))])->aggregate([1]);

        $debtor = $result->debtors->debtors[0];
        self::assertSame('Vechi Datornic SRL', $debtor->name);
        self::assertSame('33333333', $debtor->cui);
        self::assertSame('Str. Veche 7', $debtor->address);
        self::assertSame('Cluj', $debtor->addressCounty);
        self::assertSame('Tehno Furnizor SRL', $result->creditor->name);
        self::assertSame(4200.0, $result->claim->amount);
    }

    public function testTheReaderNormalisesASingularDebtorIntoAListOfOne(): void
    {
        $payloads = ExtractedDocumentData::debtorPayloadsOf($this->decode($this->legacyJson()));

        self::assertCount(1, $payloads);
        self::assertSame('Vechi Datornic SRL', $payloads[0]['name']);
    }

    public function testAnEmptyDebtorsListFallsBackToTheSingularKey(): void
    {
        // A payload written mid-migration: the list key exists but is empty and
        // the real debtor is still only under the old key. Preferring the empty
        // list would silently lose the only party in the file.
        $payload = $this->decode($this->legacyJson());
        $payload['debtors'] = [];

        $payloads = ExtractedDocumentData::debtorPayloadsOf($payload);

        self::assertCount(1, $payloads);
        self::assertSame('Vechi Datornic SRL', $payloads[0]['name']);
    }

    public function testThePopulatedListWinsOverTheMirroredSingularKey(): void
    {
        // The writer mirrors the first debtor under the old key for readers not
        // yet moved. A reader that took both would produce a phantom duplicate
        // party; the list is the source of truth.
        $payload = $this->decode($this->modernJson());
        $payload['debtor'] = $payload['debtors'][0];

        $payloads = ExtractedDocumentData::debtorPayloadsOf($payload);

        self::assertCount(1, $payloads);
    }

    public function testAMirroredSingularKeyDoesNotCreateASecondDebtorCard(): void
    {
        // The same through the prefill service: one party, one card.
        $payload = $this->decode($this->modernJson());
        $payload['debtor'] = $payload['debtors'][0];

        $debtors = $this->serviceFor([$this->documentWith(1, $payload)])->aggregateForDebtors([1])->debtors;

        self::assertCount(1, $debtors);
        self::assertSame('Vechi Datornic SRL', $debtors[0]->name);
    }

    public function testALegacyPayloadIsRecognisedAsSchemaVersionOne(): void
    {
        self::assertSame(1, ExtractedDocumentData::schemaVersionOf($this->decode($this->legacyJson())));
        self::assertSame(2, ExtractedDocumentData::schemaVersionOf($this->decode($this->modernJson())));
    }

    // ---------- fixtures, hand written ----------

    /**
     * The shape written before classification and before multiple debtors: no
     * schemaVersion marker, one `debtor` object.
     */
    private function legacyJson(): string
    {
        return <<<'JSON'
        {
            "sourceDocumentId": 1,
            "strategy": "pdf_parser",
            "globalConfidence": 0.86,
            "extractedAt": "2026-01-14T09:12:00+00:00",
            "creditor": {
                "personType": "PJ",
                "name": "Tehno Furnizor SRL",
                "cui": "12345678",
                "confidencePerField": {"personType": 0.95, "name": 0.95, "cui": 0.95}
            },
            "debtor": {
                "personType": "PJ",
                "name": "Vechi Datornic SRL",
                "cui": "33333333",
                "address": "Str. Veche 7",
                "county": "Cluj",
                "confidencePerField": {
                    "personType": 0.95, "name": 0.95, "cui": 0.95,
                    "address": 0.9, "county": 0.9
                }
            },
            "claim": {
                "amount": 4200.0,
                "currency": "RON",
                "dueDate": "2025-11-30T00:00:00+00:00",
                "confidencePerField": {"amount": 0.95, "currency": 0.95, "dueDate": 0.9}
            },
            "rawOcrText": null,
            "failureReason": null
        }
        JSON;
    }

    /**
     * The same document, same values, written in the current shape.
     */
    private function modernJson(): string
    {
        return <<<'JSON'
        {
            "schemaVersion": 2,
            "sourceDocumentId": 1,
            "strategy": "pdf_parser",
            "globalConfidence": 0.86,
            "extractedAt": "2026-01-14T09:12:00+00:00",
            "creditor": {
                "personType": "PJ",
                "name": "Tehno Furnizor SRL",
                "cui": "12345678",
                "confidencePerField": {"personType": 0.95, "name": 0.95, "cui": 0.95}
            },
            "debtors": [
                {
                    "personType": "PJ",
                    "name": "Vechi Datornic SRL",
                    "cui": "33333333",
                    "address": "Str. Veche 7",
                    "county": "Cluj",
                    "confidencePerField": {
                        "personType": 0.95, "name": 0.95, "cui": 0.95,
                        "address": 0.9, "county": 0.9
                    }
                }
            ],
            "claim": {
                "amount": 4200.0,
                "currency": "RON",
                "dueDate": "2025-11-30T00:00:00+00:00",
                "confidencePerField": {"amount": 0.95, "currency": 0.95, "dueDate": 0.9}
            },
            "rawOcrText": null,
            "failureReason": null
        }
        JSON;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function documentWith(int $id, array $payload): Document
    {
        $document = new Document();
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setExtractedData($payload);
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }

    /**
     * @param list<Document> $documents
     */
    private function serviceFor(array $documents): PrefillFromExtractionService
    {
        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findBy')->willReturn($documents);

        return new PrefillFromExtractionService($repo);
    }
}
