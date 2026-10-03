<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\BnrExchangeRate;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Repository\BnrExchangeRateRepository;
use App\Repository\DocumentRepository;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Case\ClaimItemFactory;
use PHPUnit\Framework\TestCase;

/**
 * A balance confirmation that sums up the file's invoices is not claimed a
 * second time next to them.
 */
final class BalanceSummarySettlementTest extends TestCase
{
    private const LEDGER = 'Sold neachitat total 169.251,60 din total facturat 612.405,00. Facturi neachitate integral/parțial: nr. 23381/27.11.2025 rest 456,60; nr. 23382/27.11.2025 34.298,40; nr. 23383/27.11.2025 33.666,00.';

    public function testALedgerSummingUpTheFilesInvoicesIsNotClaimedAgain(): void
    {
        $result = $this->factory([
            $this->document(1, DocumentType::FACTURA, '23381', 33666.0),
            $this->document(2, DocumentType::FACTURA, '23382', 34298.4),
            $this->document(3, DocumentType::FACTURA, '23383', 33666.0),
            $this->document(4, DocumentType::CONFIRMARE_SOLD, null, 68421.0, self::LEDGER),
        ])->collectRows([1, 2, 3, 4]);

        $ledger = $result->rows[3];
        self::assertTrue($ledger->excluded);
        self::assertContains('wizard.step3.claim_items.warning.balance_summarizes_invoices', $ledger->warningKeys);
        self::assertContains('wizard.step3.claim_items.warning.balance_below_invoices', $ledger->warningKeys, 'paid amounts explain the gap');

        $principal = array_sum(array_map(static fn ($r) => $r->willCount() ? $r->signedAmountRon() : 0.0, $result->rows));
        self::assertSame(101630.4, round($principal, 2), 'only the invoices count');
    }

    public function testABalanceConfirmationAloneStaysTheClaim(): void
    {
        $result = $this->factory([
            $this->document(4, DocumentType::CONFIRMARE_SOLD, null, 169251.6, self::LEDGER),
        ])->collectRows([4]);

        self::assertFalse($result->rows[0]->excluded);
    }

    public function testABalanceAboutOtherInvoicesIsLeftAlone(): void
    {
        $result = $this->factory([
            $this->document(1, DocumentType::FACTURA, 'FF-9001', 1000.0),
            $this->document(4, DocumentType::CONFIRMARE_SOLD, null, 5000.0, 'Sold neachitat pentru facturile 77001 și 77002.'),
        ])->collectRows([1, 4]);

        self::assertFalse($result->rows[1]->excluded);
    }

    public function testAnInvoiceNumberInsideALongerOneIsNotASummary(): void
    {
        $result = $this->factory([
            $this->document(1, DocumentType::FACTURA, '2338', 1000.0),
            $this->document(4, DocumentType::CONFIRMARE_SOLD, null, 5000.0, self::LEDGER),
        ])->collectRows([1, 4]);

        self::assertFalse($result->rows[1]->excluded, '2338 is part of 23381, not an invoice the balance names');
    }

    public function testAnInvoiceNumberWrittenInTwoWordsIsFound(): void
    {
        $result = $this->factory([
            $this->document(1, DocumentType::FACTURA, 'FF0012/2025', 1000.0),
            $this->document(4, DocumentType::CONFIRMARE_SOLD, null, 1000.0, 'Sold neachitat: factura FF 0012/2025.'),
        ])->collectRows([1, 4]);

        self::assertTrue($result->rows[1]->excluded);
    }

    /**
     * @param list<Document> $documents
     */
    private function factory(array $documents): ClaimItemFactory
    {
        $repository = $this->createStub(DocumentRepository::class);
        $repository->method('findBy')->willReturn($documents);
        $rates = $this->createStub(BnrExchangeRateRepository::class);
        $rates->method('findRateValidAt')->willReturn(new BnrExchangeRate());

        return new ClaimItemFactory($repository, new CurrencyConverter($rates));
    }

    private function document(int $id, DocumentType $type, ?string $number, float $amount, string $description = 'Factura GRAU MARFA'): Document
    {
        $document = new Document();
        $document->setDocumentType($type);
        $document->setExtractedData([
            'schemaVersion' => 2,
            'sourceDocumentId' => $id,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'claim' => array_filter([
                'amount' => $amount,
                'currency' => 'RON',
                'invoiceNumber' => $number,
                'invoiceDate' => '2025-11-27',
                'dueDate' => '2025-11-27',
                'description' => $description,
                'confidencePerField' => ['amount' => 0.95],
            ], static fn ($v) => $v !== null),
        ]);
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }
}
