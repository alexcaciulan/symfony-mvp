<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ConflictOption;
use App\DTO\Extraction\ConflictResolution;
use App\DTO\Extraction\PrefillConflict;
use App\Entity\Document;
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
    public function testTwoRealDebtorsAreOfferedAsAChoiceOfParty(): void
    {
        $result = $this->serviceFor([
            $this->document(1, DocumentType::CONTRACT, $this->debtor('Alfa Construct SRL', '11111111')),
            $this->document(2, DocumentType::FACTURA, $this->debtor('Beta Logistic SRL', '22222222')),
        ])->aggregate([1, 2]);

        // One card while the product allows one debtor, the first party until
        // the lawyer chooses.
        self::assertCount(1, $result->debtors->debtors);
        self::assertSame('Alfa Construct SRL', $result->debtors->debtors[0]->name);

        $choice = $this->partyChoice($result->conflicts);
        self::assertTrue($choice->blocks());
        self::assertFalse($choice->manualAllowed);
        self::assertNull($choice->suggestedIndex, 'no party is suggested');
        self::assertSame(
            ['Alfa Construct SRL (CUI 11111111)', 'Beta Logistic SRL (CUI 22222222)'],
            array_map(static fn (ConflictOption $o): string => $o->displayValue(), $choice->options),
        );
    }

    public function testTheChosenPartyIsTheOneOnTheCard(): void
    {
        $service = $this->serviceFor([
            $this->document(1, DocumentType::CONTRACT, $this->debtor('Alfa Construct SRL', '11111111')),
            $this->document(2, DocumentType::FACTURA, $this->debtor('Beta Logistic SRL', '22222222')),
        ]);
        $choice = $this->partyChoice($service->aggregate([1, 2])->conflicts);
        $picked = new ConflictResolution(
            conflictKey: $choice->key(),
            scope: $choice->scope,
            field: $choice->field,
            entityKey: $choice->entityKey,
            value: $choice->options[1]->value,
            optionIndex: 1,
            documentId: $choice->options[1]->documentId,
            optionSignature: $choice->options[1]->displayValue(),
        );

        $debtors = $service->aggregate([1, 2], [$choice->key() => $picked])->debtors->debtors;

        self::assertCount(1, $debtors);
        self::assertSame('Beta Logistic SRL', $debtors[0]->name);
        self::assertSame('22222222', $debtors[0]->cui);
    }

    public function testOneDocumentNamingTwoDebtorsOffersBoth(): void
    {
        $document = $this->document(1, DocumentType::CONTRACT, $this->debtor('Alfa SRL', '11111111'));
        $payload = $document->getExtractedData();
        $payload['debtors'][] = $this->debtor('Beta SRL', '22222222');
        $document->setExtractedData($payload);

        $result = $this->serviceFor([$document])->aggregate([1]);

        self::assertCount(2, $this->partyChoice($result->conflicts)->options);
    }

    public function testEveryPartyIsOfferedRatherThanTruncatedSilently(): void
    {
        $documents = [];
        for ($i = 1; $i <= 7; ++$i) {
            $documents[] = $this->document(
                $i,
                DocumentType::FACTURA,
                $this->debtor('Debitor ' . $i . ' SRL', str_pad((string) $i, 8, '9', STR_PAD_LEFT)),
            );
        }

        $result = $this->serviceFor($documents)->aggregate(range(1, count($documents)));

        self::assertCount(1, $result->debtors->debtors);
        self::assertCount(7, $this->partyChoice($result->conflicts)->options);
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
     * @param list<PrefillConflict> $conflicts
     */
    private function partyChoice(array $conflicts): PrefillConflict
    {
        foreach ($conflicts as $conflict) {
            if ($conflict->messageKey === 'wizard.conflict.debtor_set.choose') {
                return $conflict;
            }
        }
        self::fail('the documents name several parties, so the lawyer must be asked to choose');
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
