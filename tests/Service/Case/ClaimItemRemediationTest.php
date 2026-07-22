<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\DTO\Wizard\ClaimItemRow;
use App\Entity\ClaimItem;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\ClaimItemKind;
use App\Enum\DocumentType;
use App\Repository\BnrExchangeRateRepository;
use App\Repository\DocumentRepository;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Case\ClaimItemFactory;
use App\Service\Case\ClaimTotalsService;
use PHPUnit\Framework\TestCase;

/**
 * The behaviours the code review and the legal review found missing: a storno
 * that subtracts, a duplicate that stays visible, and scalars that are not
 * erased by positions carrying nothing.
 */
class ClaimItemRemediationTest extends TestCase
{
    private ClaimItemFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ClaimItemFactory($this->documentsReturning([]), $this->converter());
    }

    /**
     * @param list<Document> $documents
     */
    private function documentsReturning(array $documents): DocumentRepository
    {
        return new class($documents) extends DocumentRepository {
            /** @param list<Document> $documents */
            public function __construct(private array $documents)
            {
                // Only findBy() is exercised.
            }

            /** @return list<Document> */
            public function findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null): array
            {
                return $this->documents;
            }
        };
    }

    private function converter(): CurrencyConverter
    {
        $repository = new class extends BnrExchangeRateRepository {
            public function __construct()
            {
                // No rates: every position in this test is in RON.
            }

            public function findRateValidAt(string $currency, \DateTimeInterface $date): ?\App\Entity\BnrExchangeRate
            {
                return null;
            }
        };

        return new CurrencyConverter($repository);
    }

    public function testAStornoPrintedWithAPositiveTotalSubtractsInsteadOfAdding(): void
    {
        $case = new LegalCase();
        $this->factory->materialize($case, [
            $this->row('FF-100', 10000.0),
            $this->row('FF-101', 10000.0, kind: ClaimItemKind::CREDIT_NOTE),
        ]);

        $totals = (new ClaimTotalsService())->totals($case->getClaimItems());

        $this->assertSame(
            0.0,
            $totals->principalRon,
            'An invoice and its storno must cancel out, not add up to twice the claim.',
        );
    }

    public function testAStornoAccruesNothing(): void
    {
        $item = new ClaimItem();
        $item->setKind(ClaimItemKind::CREDIT_NOTE);
        $item->setAmountRon('5000.00');

        $this->assertSame(-5000.0, $item->signedAmountRon());
        $this->assertTrue($item->isCreditNote());
    }

    public function testAnInvoiceLabelledStornoBecomesACreditNoteEvenWithAPositiveTotal(): void
    {
        $factory = new ClaimItemFactory(
            $this->documentsReturning([
                $this->invoiceDocument(1, 'FS-9/2025', 3000.0, 'Factura storno aferenta FF-100'),
            ]),
            $this->converter(),
        );

        $rows = $factory->rowsFromDocuments([1]);

        $this->assertCount(1, $rows);
        $this->assertSame(ClaimItemKind::CREDIT_NOTE, $rows[0]->kind);
        $this->assertSame(-3000.0, $rows[0]->signedAmountRon());
        $this->assertTrue(
            $rows[0]->requiresIndividualConfirmation(0.8),
            'A storno changes the claim downwards; the table-wide tick must not cover it.',
        );
    }

    public function testADuplicateIsKeptAsAnExcludedRowInsteadOfDisappearing(): void
    {
        $factory = new ClaimItemFactory(
            $this->documentsReturning([
                $this->invoiceDocument(1, 'FF 0012/2025', 1000.0),
                $this->invoiceDocument(2, 'FF12/2025', 4000.0),
            ]),
            $this->converter(),
        );

        $rows = $factory->rowsFromDocuments([1, 2]);

        $this->assertCount(2, $rows, 'The duplicate must stay on the table, not vanish from the claim.');
        $this->assertFalse($rows[0]->excluded);
        $this->assertTrue($rows[1]->excluded);
        $this->assertContains('wizard.step3.claim_items.warning.duplicate', $rows[1]->warningKeys);
        $this->assertNotSame(
            $rows[0]->dedupKey,
            $rows[1]->dedupKey,
            'Two positions on one case cannot share a dedup key: the unique constraint would fail the submit.',
        );
    }

    public function testMaterializeNeverEmitsTwoPositionsWithTheSameKey(): void
    {
        $case = new LegalCase();
        $items = $this->factory->materialize($case, [
            new ClaimItemRow(dedupKey: 'inv:same', amount: 100.0, amountRon: 100.0),
            new ClaimItemRow(dedupKey: 'inv:same', amount: 200.0, amountRon: 200.0),
        ]);

        $this->assertNotSame($items[0]->getDedupKey(), $items[1]->getDedupKey());
    }

    public function testCauseKeyCollapsesTheSameContractWrittenTwoWays(): void
    {
        $a = new ClaimItem();
        $a->setCauseReference('Contract nr. 12/2024');
        $b = new ClaimItem();
        $b->setCauseReference('  CONTRACT 12/2024 ');

        $this->assertSame($a->causeKey(), $b->causeKey());
    }

    private function row(string $number, float $amount, ClaimItemKind $kind = ClaimItemKind::INVOICE): ClaimItemRow
    {
        return new ClaimItemRow(
            dedupKey: 'inv:' . $number,
            amount: $kind === ClaimItemKind::CREDIT_NOTE ? -$amount : $amount,
            kind: $kind,
            documentNumber: $number,
            dueDate: new \DateTimeImmutable('2025-01-31'),
            amountRon: $kind === ClaimItemKind::CREDIT_NOTE ? -$amount : $amount,
            confirmed: true,
        );
    }

    private function invoiceDocument(int $id, string $number, float $amount, ?string $description = null): Document
    {
        $document = new class($id) extends Document {
            public function __construct(private int $identifier) {}

            public function getId(): ?int
            {
                return $this->identifier;
            }
        };
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setExtractionConfidence('0.95');
        $document->setExtractedData([
            'claim' => [
                'amount' => $amount,
                'currency' => 'RON',
                'invoiceNumber' => $number,
                'invoiceDate' => '2025-01-01',
                'dueDate' => '2025-01-31',
                'description' => $description,
            ],
        ]);

        return $document;
    }
}
