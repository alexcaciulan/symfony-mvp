<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Wizard\Step2DebtorsData;
use App\Entity\Document;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use App\Repository\DocumentRepository;
use App\Service\Extraction\PrefillFromExtractionService;
use PHPUnit\Framework\TestCase;

/**
 * The wizard used to collapse every debtor in every document into one entry.
 * With two real debtors that produced a party made of one company's name and
 * another's registration number, in a filing.
 */
final class MultiDebtorPrefillTest extends TestCase
{
    public function testTwoRealDebtorsProduceTwoEntries(): void
    {
        $service = $this->serviceFor([
            $this->document(1, DocumentType::CONTRACT, $this->debtor('Alfa Construct SRL', '11111111')),
            $this->document(2, DocumentType::FACTURA, $this->debtor('Beta Logistic SRL', '22222222')),
        ]);

        $debtors = $service->aggregateForDebtors([1, 2])->debtors;

        self::assertCount(2, $debtors);
        self::assertSame('Alfa Construct SRL', $debtors[0]->name);
        self::assertSame('11111111', $debtors[0]->cui);
        self::assertSame('Beta Logistic SRL', $debtors[1]->name);
        self::assertSame('22222222', $debtors[1]->cui);
    }

    public function testOneDebtorSeenTwiceStaysOneEntryAndGainsTheUnion(): void
    {
        $sparse = $this->debtor('S.C. ALFA CONSTRUCT S.R.L.', 'RO 11111111');
        $detailed = $this->debtor('Alfa Construct SRL', '11111111');
        $detailed['address'] = 'Str. Lunga 12';
        $detailed['county'] = 'Cluj';
        $detailed['confidencePerField']['address'] = 0.9;
        $detailed['confidencePerField']['county'] = 0.9;

        $service = $this->serviceFor([
            $this->document(1, DocumentType::CONTRACT, $sparse),
            $this->document(2, DocumentType::FACTURA, $detailed),
        ]);

        $debtors = $service->aggregateForDebtors([1, 2])->debtors;

        self::assertCount(1, $debtors);
        self::assertSame('Str. Lunga 12', $debtors[0]->address);
        self::assertSame('Cluj', $debtors[0]->addressCounty);
    }

    public function testOneDocumentNamingTwoDebtorsProducesTwoEntries(): void
    {
        $document = $this->document(1, DocumentType::CONTRACT, $this->debtor('Alfa SRL', '11111111'));
        $payload = $document->getExtractedData();
        $payload['debtors'][] = $this->debtor('Beta SRL', '22222222');
        $document->setExtractedData($payload);

        $debtors = $this->serviceFor([$document])->aggregateForDebtors([1])->debtors;

        self::assertCount(2, $debtors);
    }

    public function testMoreDebtorsThanTheCapAreReportedRatherThanTruncatedSilently(): void
    {
        $documents = [];
        for ($i = 1; $i <= Step2DebtorsData::MAX_DEBTORS + 2; ++$i) {
            $documents[] = $this->document(
                $i,
                DocumentType::FACTURA,
                $this->debtor('Debitor ' . $i . ' SRL', str_pad((string) $i, 8, '9', STR_PAD_LEFT)),
            );
        }

        $result = $this->serviceFor($documents)->aggregate(range(1, count($documents)));

        self::assertCount(Step2DebtorsData::MAX_DEBTORS, $result->debtors->debtors);
        self::assertTrue($result->hasBlockingConflicts());
        $blocking = $result->blockingConflicts()[0];
        self::assertSame(ConflictScope::DEBTOR_SET, $blocking->scope);
        self::assertSame('wizard.conflict.debtor_set.too_many', $blocking->messageKey);
    }

    public function testTwoDebtorsAreAnnouncedEvenWhenNothingIsWrong(): void
    {
        $result = $this->serviceFor([
            $this->document(1, DocumentType::CONTRACT, $this->debtor('Alfa SRL', '11111111')),
            $this->document(2, DocumentType::FACTURA, $this->debtor('Beta SRL', '22222222')),
        ])->aggregate([1, 2]);

        self::assertFalse($result->hasBlockingConflicts());
        $info = $result->conflictsOfSeverity(ConflictSeverity::INFO);
        self::assertNotSame([], $info);
        self::assertSame('wizard.conflict.debtor_set.multiple', $info[0]->messageKey);
    }

    public function testAPayloadWithASingleDebtorObjectStillReads(): void
    {
        // Payloads written before a document could name more than one debtor are
        // not migrated: the payload is evidence of what was extracted, and
        // rewriting it would make the case file disagree with its own audit
        // trail. The reader normalises instead.
        $document = new Document();
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setExtractedData([
            'sourceDocumentId' => 1,
            'strategy' => 'pdf_parser',
            'globalConfidence' => 0.8,
            'debtor' => $this->debtor('Vechi Datornic SRL', '33333333'),
        ]);
        $this->setId($document, 1);

        $debtors = $this->serviceFor([$document])->aggregateForDebtors([1])->debtors;

        self::assertCount(1, $debtors);
        self::assertSame('Vechi Datornic SRL', $debtors[0]->name);
        self::assertSame('33333333', $debtors[0]->cui);
    }

    public function testNoDocumentsStillYieldsOnePrimaryCard(): void
    {
        $debtors = $this->serviceFor([])->aggregateForDebtors([])->debtors;

        self::assertCount(1, $debtors);
        self::assertNull($debtors[0]->name);
    }

    /**
     * @return array<string, mixed>
     */
    private function debtor(string $name, string $cui): array
    {
        return [
            'personType' => 'PJ',
            'name' => $name,
            'cui' => $cui,
            'confidencePerField' => ['personType' => 0.95, 'name' => 0.95, 'cui' => 0.95],
        ];
    }

    /**
     * @param array<string, mixed> $debtor
     */
    private function document(int $id, DocumentType $type, array $debtor): Document
    {
        $document = new Document();
        $document->setDocumentType($type);
        $document->setExtractedData([
            'schemaVersion' => 2,
            'sourceDocumentId' => $id,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'debtors' => [$debtor],
        ]);
        $this->setId($document, $id);

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

    private function setId(Document $document, int $id): void
    {
        $property = new \ReflectionProperty(Document::class, 'id');
        $property->setValue($document, $id);
    }
}
