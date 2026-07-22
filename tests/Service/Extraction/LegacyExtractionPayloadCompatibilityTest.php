<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ClaimExtraction;
use App\DTO\Extraction\CreditorExtraction;
use App\DTO\Extraction\DebtorExtraction;
use App\DTO\Extraction\DocumentClassification;
use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Enum\LegalGroundCategory;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Repository\DocumentRepository;
use App\Service\Extraction\PrefillFromExtractionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cases extracted before the payload carried a classification must keep
 * prefilling the wizard exactly as they did. No migration rewrites those rows,
 * so every one of them is read by the current aggregator on the next visit to
 * step 1, and a reader that quietly ignores an old key loses evidence a lawyer
 * already reviewed.
 *
 * The old payload here is written out by hand, key by key, rather than produced
 * by the current serialiser: a fixture built from today's code cannot catch a
 * change in today's code.
 */
final class LegacyExtractionPayloadCompatibilityTest extends TestCase
{
    /**
     * The exact shape `ExtractedDocumentData::toArray()` wrote before the
     * classification work: no `schemaVersion`, no `classification`, no
     * `failureReason`.
     *
     * @return array<string, mixed>
     */
    private static function legacyPayload(): array
    {
        return [
            'sourceDocumentId' => 7,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.72,
            'extractedAt' => '2026-03-11T09:30:00+00:00',
            'creditor' => [
                'personType' => 'PJ',
                'name' => 'Tehno Construct SRL',
                'cui' => '12345678',
                'isVatPayer' => true,
                'personalId' => null,
                'onrcNumber' => 'J40/1234/2025',
                'address' => 'Bd. Demo 100',
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
                'personType' => 'PJ',
                'name' => 'Datornic Trans SA',
                'cui' => '87654321',
                'isVatPayer' => false,
                'personalId' => null,
                'onrcNumber' => 'J12/5678/2020',
                'address' => 'Str. Datornic 5',
                'county' => 'Cluj',
                'locality' => 'Cluj-Napoca',
                'email' => 'office@datornic.ro',
                'phone' => '0722000111',
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
            'claim' => [
                'amount' => 12000.0,
                'currency' => 'RON',
                'dueDate' => '2026-02-01T00:00:00+00:00',
                'legalGround' => 'FACTURA_ACCEPTATA',
                'description' => 'Servicii consultanță Q4 2025',
                'invoiceNumber' => 'MJ 2026-00042',
                'invoiceDate' => '2026-01-10T00:00:00+00:00',
                'contractNumber' => '45/2025',
                'contractDate' => '2025-12-01T00:00:00+00:00',
                'contractReference' => 'contract de prestări servicii',
                'penaltyType' => 'CONTRACTUAL',
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
            'rawOcrText' => null,
        ];
    }

    /**
     * The same extraction as {@see self::legacyPayload()}, written by the
     * current serialiser. Same values, new envelope.
     *
     * @return array<string, mixed>
     */
    private static function currentPayload(): array
    {
        $legacy = self::legacyPayload();

        return (new ExtractedDocumentData(
            sourceDocumentId: 7,
            strategy: 'ai_vision',
            globalConfidence: 0.72,
            extractedAt: new \DateTimeImmutable('2026-03-11T09:30:00+00:00'),
            creditor: new CreditorExtraction(
                personType: PersonType::PJ,
                name: 'Tehno Construct SRL',
                cui: '12345678',
                isVatPayer: true,
                personalId: null,
                onrcNumber: 'J40/1234/2025',
                address: 'Bd. Demo 100',
                county: 'București',
                locality: 'Sector 1',
                email: 'contact@tehno.ro',
                phone: '0721234567',
                iban: 'RO49AAAA1B31007593840000',
                legalRepresentative: 'Popescu Ion',
                bankName: 'Banca Transilvania',
                confidencePerField: $legacy['creditor']['confidencePerField'],
            ),
            debtors: [new DebtorExtraction(
                personType: PersonType::PJ,
                name: 'Datornic Trans SA',
                cui: '87654321',
                isVatPayer: false,
                personalId: null,
                onrcNumber: 'J12/5678/2020',
                address: 'Str. Datornic 5',
                county: 'Cluj',
                locality: 'Cluj-Napoca',
                email: 'office@datornic.ro',
                phone: '0722000111',
                iban: 'RO49AAAA1B31007593840000',
                administrator: 'Ionescu Maria',
                confidencePerField: $legacy['debtor']['confidencePerField'],
            )],
            claim: new ClaimExtraction(
                amount: 12000.0,
                currency: 'RON',
                dueDate: new \DateTimeImmutable('2026-02-01T00:00:00+00:00'),
                legalGround: LegalGroundCategory::FACTURA_ACCEPTATA,
                description: 'Servicii consultanță Q4 2025',
                invoiceNumber: 'MJ 2026-00042',
                invoiceDate: new \DateTimeImmutable('2026-01-10T00:00:00+00:00'),
                contractNumber: '45/2025',
                contractDate: new \DateTimeImmutable('2025-12-01T00:00:00+00:00'),
                contractReference: 'contract de prestări servicii',
                penaltyType: PenaltyType::CONTRACTUAL,
                contractualPenaltyRate: 0.1,
                confidencePerField: $legacy['claim']['confidencePerField'],
            ),
            classification: new DocumentClassification(DocumentType::FACTURA, 0.93),
        ))->toArray();
    }

    public function testALegacyPayloadPrefillsExactlyLikeItsCurrentEquivalent(): void
    {
        $legacy = $this->serviceFor([$this->documentWith(7, self::legacyPayload())]);
        $current = $this->serviceFor([$this->documentWith(7, self::currentPayload())]);

        // Whole-object comparison rather than a field list: a field added to a
        // step DTO later is then covered here without anyone remembering to
        // extend the assertion.
        self::assertEquals($current->aggregateForCreditor([7]), $legacy->aggregateForCreditor([7]));
        self::assertEquals($current->aggregateForDebtor([7]), $legacy->aggregateForDebtor([7]));
        self::assertEquals($current->aggregateForClaim([7]), $legacy->aggregateForClaim([7]));
        self::assertEquals($current->aggregateForDebtors([7]), $legacy->aggregateForDebtors([7]));
    }

    public function testALegacyPayloadStillFillsEveryFieldItUsedTo(): void
    {
        // The equivalence test above would also pass if BOTH shapes stopped
        // prefilling. Pin the values themselves so it cannot.
        $service = $this->serviceFor([$this->documentWith(7, self::legacyPayload())]);

        $creditor = $service->aggregateForCreditor([7]);
        self::assertSame('Tehno Construct SRL', $creditor->name);
        self::assertSame('București', $creditor->addressCounty);
        self::assertSame('Banca Transilvania', $creditor->bankName);
        self::assertCount(12, $creditor->autoFilled);

        $debtor = $service->aggregateForDebtor([7]);
        self::assertSame('Datornic Trans SA', $debtor->name);
        self::assertSame('Cluj-Napoca', $debtor->addressLocality);
        self::assertCount(11, $debtor->autoFilled);

        $claim = $service->aggregateForClaim([7]);
        self::assertSame(12000.0, $claim->amount);
        self::assertNotNull($claim->dueDate);
        self::assertSame('2026-02-01', $claim->dueDate->format('Y-m-d'));
        self::assertSame(PenaltyType::CONTRACTUAL, $claim->penaltyType);
        self::assertCount(12, $claim->autoFilled);
    }

    public function testAWizardSessionMixingBothShapesAggregatesAcrossThem(): void
    {
        // The realistic state of an in-flight case: documents extracted before
        // the change sit in the same session bag as ones extracted after it.
        $old = self::legacyPayload();
        $old['creditor']['name'] = 'Nume Vechi SRL';
        $old['creditor']['confidencePerField']['name'] = 0.99;

        $new = self::currentPayload();
        $new['creditor']['name'] = 'Nume Nou SRL';
        $new['creditor']['confidencePerField']['name'] = 0.85;

        $service = $this->serviceFor([
            $this->documentWith(7, $old),
            $this->documentWith(8, $new),
        ]);

        // Selection is by confidence alone. The envelope version must not act
        // as a tie-breaker, in either direction.
        self::assertSame('Nume Vechi SRL', $service->aggregateForCreditor([7, 8])->name);
    }

    public function testAnUnmarkedPayloadReadsAsTheOriginalSchema(): void
    {
        self::assertSame(1, ExtractedDocumentData::schemaVersionOf(self::legacyPayload()));
        self::assertSame(2, ExtractedDocumentData::schemaVersionOf(self::currentPayload()));
    }

    /**
     * @param mixed $marker
     */
    #[DataProvider('unusableMarkers')]
    public function testAnUnusableVersionMarkerReadsAsTheOriginalSchema(mixed $marker): void
    {
        // A payload hand-edited in the database, or written by a future
        // serialiser with a different idea of the type, must degrade to the
        // conservative reading rather than to an unrecognised version.
        self::assertSame(1, ExtractedDocumentData::schemaVersionOf(['schemaVersion' => $marker]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableMarkers(): iterable
    {
        yield 'null' => [null];
        yield 'numeric string' => ['2'];
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'float' => [2.0];
        yield 'array' => [[2]];
    }

    public function testALegacyPayloadCarriesNoClassification(): void
    {
        $legacy = self::legacyPayload();

        self::assertNull(DocumentClassification::fromArray($legacy['classification'] ?? null));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function documentWith(int $id, array $payload): Document
    {
        $document = new Document();
        $document->setDocumentType(DocumentType::ALT_DOCUMENT);
        $document->setOriginalFilename('legacy.pdf');
        $document->setStoredFilename('legacy.pdf');
        $document->setFileSize(1024);
        $document->setMimeType('application/pdf');
        $document->setExtractedData($payload);
        (new \ReflectionClass($document))->getProperty('id')->setValue($document, $id);

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
