<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\Entity\Document;
use App\Enum\ConflictScope;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use App\Repository\DocumentRepository;
use App\Service\Extraction\PrefillFromExtractionService;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial review of determinism (scenario 2) and debtor clustering
 * (scenario 4), driven through the public prefill service rather than the
 * aggregator alone: two runs over one set of documents must produce one filing,
 * down to provenance and conflict identity, and the number of debtor cards must
 * be the number of parties the documents actually name.
 */
final class T5DeterminismClusteringReviewTest extends TestCase
{
    // ---------- scenario 2: determinism through the whole service ----------

    public function testTheWholePrefillIsIdenticalAcrossTwoRunsInReversedOrder(): void
    {
        $forward = [
            $this->debtorDocument(1, DocumentType::FACTURA, 'Alfa SRL', '11111111'),
            $this->debtorDocument(2, DocumentType::FACTURA, 'Beta SRL', '22222222'),
        ];
        $backward = array_reverse($forward);

        // findBy orders by id ASC regardless of argument order; emulate that so
        // the test exercises the ordering the real repository imposes.
        $one = $this->serviceForOrdered($forward)->aggregate([1, 2]);
        $two = $this->serviceForOrdered($backward)->aggregate([2, 1]);

        self::assertEquals($one->debtors, $two->debtors);
        self::assertEquals($one->creditor, $two->creditor);
        self::assertEquals($one->claim, $two->claim);
        self::assertEquals($one->provenance, $two->provenance);
        self::assertSame($this->conflictKeys($one), $this->conflictKeys($two));
    }

    public function testEqualConfidenceLeavesOnlyTheDocumentIdToDecideAndItDecidesStably(): void
    {
        // Two documents, identical type and identical confidence, contradictory
        // identity. Nothing but the id can break the tie; it must break the same
        // way on every run. Modelled as one debtor cluster is impossible here
        // (different CUIs), so this is really two clusters, but the claim-side
        // headline aggregation on a single-debtor-per-doc claim still has to be
        // stable. We assert on the debtor set order instead: lowest id first.
        $docs = [
            $this->debtorDocument(7, DocumentType::FACTURA, 'Sapte SRL', '77777777'),
            $this->debtorDocument(4, DocumentType::FACTURA, 'Patru SRL', '44444444'),
        ];

        $a = $this->serviceForOrdered($docs)->aggregate([7, 4])->debtors->debtors;
        $b = $this->serviceForOrdered($docs)->aggregate([4, 7])->debtors->debtors;

        self::assertSame('Patru SRL', $a[0]->name, 'the lowest document id anchors the first card');
        self::assertSame('Sapte SRL', $a[1]->name);
        self::assertEquals($a, $b);
    }

    // ---------- scenario 4a: same party, different spelling, merges ----------

    public function testTwoSpellingsWithOneCuiBecomeOneCard(): void
    {
        $sparse = $this->debtorDocument(1, DocumentType::CONTRACT, 'S.C. ALFA S.R.L.', 'RO 11111111');
        $detailed = $this->debtorDocument(2, DocumentType::FACTURA, 'Alfa SRL', '11111111', [
            'address' => 'Str. Lunga 12',
            'county' => 'Cluj',
        ]);

        $debtors = $this->serviceForOrdered([$sparse, $detailed])->aggregateForDebtors([1, 2])->debtors;

        self::assertCount(1, $debtors);
        self::assertSame('Str. Lunga 12', $debtors[0]->address);
        self::assertSame('Cluj', $debtors[0]->addressCounty);
    }

    public function testTwoSpellingsWithNoCuiButNearIdenticalNamesMerge(): void
    {
        // No registration number on either side; the names are close enough to
        // pass the stricter clustering bar. One card, not two.
        $first = $this->debtorDocumentNoCui(1, DocumentType::CONTRACT, 'S.C. ALFA CONSTRUCT S.R.L.');
        $second = $this->debtorDocumentNoCui(2, DocumentType::NOTIFICARE, 'Alfa Construct SRL');

        $debtors = $this->serviceForOrdered([$first, $second])->aggregateForDebtors([1, 2])->debtors;

        self::assertCount(1, $debtors);
    }

    // ---------- scenario 4b: similar names, different CUIs, stay apart ----------

    public function testSimilarNamesWithDifferentCuisAreNeverMerged(): void
    {
        // "Alfa SRL" and "Alfa Prod SRL" would pass a naive name comparison, but
        // the registration numbers prove two companies. Merging them would file
        // against a party assembled from two.
        $a = $this->debtorDocument(1, DocumentType::FACTURA, 'Alfa SRL', '11111111');
        $b = $this->debtorDocument(2, DocumentType::FACTURA, 'Alfa Prod SRL', '22222222');

        $debtors = $this->serviceForOrdered([$a, $b])->aggregateForDebtors([1, 2])->debtors;

        self::assertCount(2, $debtors);
        $cuis = array_map(static fn ($d) => $d->cui, $debtors);
        sort($cuis);
        self::assertSame(['11111111', '22222222'], $cuis);
    }

    public function testIdenticalNamesWithDifferentCuisStayApart(): void
    {
        // The hardest case for the name heuristic: identical names, different
        // registration numbers. The number must win, two cards.
        $a = $this->debtorDocument(1, DocumentType::FACTURA, 'Transport SRL', '11111111');
        $b = $this->debtorDocument(2, DocumentType::FACTURA, 'Transport SRL', '99999999');

        $debtors = $this->serviceForOrdered([$a, $b])->aggregateForDebtors([1, 2])->debtors;

        self::assertCount(2, $debtors);
    }

    // ---------- scenario 4c: over the cap is signalled, not truncated silently ----------

    public function testSixDistinctDebtorsAreSignalledAndCappedNotDroppedSilently(): void
    {
        $docs = [];
        for ($i = 1; $i <= 6; ++$i) {
            $docs[] = $this->debtorDocument(
                $i,
                DocumentType::FACTURA,
                'Debitor ' . $i . ' SRL',
                str_pad((string) $i, 8, '1', STR_PAD_LEFT),
            );
        }

        $result = $this->serviceForOrdered($docs)->aggregate(range(1, 6));

        self::assertCount(5, $result->debtors->debtors, 'capped at the product limit');
        self::assertTrue($result->hasBlockingConflicts(), 'the truncation is a blocking conflict, never silent');
        $blocking = $result->blockingConflicts()[0];
        self::assertSame(ConflictScope::DEBTOR_SET, $blocking->scope);
        self::assertSame(ConflictSeverity::ERROR, $blocking->severity);
        self::assertSame('wizard.conflict.debtor_set.too_many', $blocking->messageKey);
    }

    // ---------- helpers ----------

    /**
     * @param \App\DTO\Extraction\WizardPrefillResult $result
     * @return list<string>
     */
    private function conflictKeys(\App\DTO\Extraction\WizardPrefillResult $result): array
    {
        return array_map(static fn ($c) => $c->key(), $result->conflicts);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function debtorDocument(int $id, DocumentType $type, string $name, string $cui, array $extra = []): Document
    {
        $debtor = ['personType' => 'PJ', 'name' => $name, 'cui' => $cui] + $extra;
        $confidence = [];
        foreach ($debtor as $field => $_) {
            $confidence[$field] = 0.95;
        }
        $debtor['confidencePerField'] = $confidence;

        return $this->document($id, $type, $debtor);
    }

    private function debtorDocumentNoCui(int $id, DocumentType $type, string $name): Document
    {
        return $this->document($id, $type, [
            'personType' => 'PJ',
            'name' => $name,
            'confidencePerField' => ['personType' => 0.95, 'name' => 0.95],
        ]);
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
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }

    /**
     * A service whose repository returns the documents ordered by id ASC, as the
     * real one does, so the test cannot accidentally rely on argument order.
     *
     * @param list<Document> $documents
     */
    private function serviceForOrdered(array $documents): PrefillFromExtractionService
    {
        $ordered = $documents;
        usort($ordered, static fn (Document $a, Document $b): int => ($a->getId() ?? 0) <=> ($b->getId() ?? 0));

        $repo = $this->createStub(DocumentRepository::class);
        $repo->method('findBy')->willReturn($ordered);

        return new PrefillFromExtractionService($repo);
    }
}
