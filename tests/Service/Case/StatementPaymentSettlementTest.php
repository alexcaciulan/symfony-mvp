<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\DTO\Wizard\ClaimItemRow;
use App\Entity\BnrExchangeRate;
use App\Entity\Document;
use App\Enum\DocumentType;
use App\Repository\BnrExchangeRateRepository;
use App\Repository\DocumentRepository;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Case\ClaimItemFactory;
use PHPUnit\Framework\TestCase;

/**
 * Payments an account statement or a bank statement lists, tied to the
 * invoices they name: a fully paid invoice leaves the claim, a partly paid one
 * records what was paid without imputing it.
 */
final class StatementPaymentSettlementTest extends TestCase
{
    private const PAID_IN_FULL = 'wizard.step3.claim_items.warning.paid_in_full_by_statement';
    private const PAID_IN_PART = 'wizard.step3.claim_items.warning.payment_in_statement';
    private const UNMATCHED = 'wizard.conflict.claim_item.payment_unmatched';

    public function testAnInvoiceTheAccountStatementStatesAsFullyPaidIsExcluded(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'LDT 20951', 5000.0),
            $this->invoice(2, 'LDT 20954', 3000.0),
            $this->invoice(3, 'LDT 20960', 7000.0),
            $this->accountStatement(4, [
                ['invoiceReferences' => ['20951', '20954'], 'fullyPaid' => true],
            ]),
        ])->collectRows([1, 2, 3, 4]);

        $first = $this->row($result->rows, 'LDT 20951');
        $second = $this->row($result->rows, 'LDT 20954');
        $open = $this->row($result->rows, 'LDT 20960');

        self::assertTrue($first->excluded);
        self::assertContains(self::PAID_IN_FULL, $first->warningKeys);
        self::assertTrue($second->excluded);
        self::assertTrue($first->statementPayments[0]['fullyPaid']);
        self::assertFalse($open->excluded);
        self::assertSame([], $open->statementPayments);
        self::assertSame([], $result->conflicts);
    }

    public function testPaymentsCoveringTheInvoicedTotalExcludeThePositionAndShowEachPayment(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0),
            $this->bankStatement(2, [
                ['amount' => 4000.0, 'currency' => 'RON', 'date' => '2025-04-10', 'invoiceReferences' => ['MJ-9/2025']],
                ['amount' => 6000.0, 'currency' => 'RON', 'date' => '2025-05-02', 'invoiceReferences' => ['MJ 9/2025']],
            ]),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertTrue($row->excluded);
        self::assertContains(self::PAID_IN_FULL, $row->warningKeys);
        self::assertSame(10000.0, $row->paidAmount);
        self::assertSame(['2025-04-10', '2025-05-02'], array_column($row->statementPayments, 'date'));
        self::assertSame([4000.0, 6000.0], array_column($row->statementPayments, 'amount'));
        self::assertSame(10000.0, $row->amount, 'the invoiced total is not rewritten');
    }

    public function testACurrencyWrittenAsLeiMatchesARonInvoice(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0),
            $this->bankStatement(2, [
                ['amount' => 10000.0, 'currency' => 'lei', 'date' => '2025-04-10', 'invoiceReferences' => ['MJ-9/2025']],
            ]),
        ])->collectRows([1, 2]);

        self::assertTrue($result->rows[0]->excluded);
        self::assertSame(10000.0, $result->rows[0]->paidAmount);
    }

    public function testAnInvoiceWrittenInLeiMatchesARonPayment(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0, 'lei'),
            $this->bankStatement(2, [
                ['amount' => 10000.0, 'currency' => 'RON', 'date' => '2025-04-10', 'invoiceReferences' => ['MJ-9/2025']],
            ]),
        ])->collectRows([1, 2]);

        self::assertSame('RON', $result->rows[0]->currency);
        self::assertTrue($result->rows[0]->excluded);
    }

    public function testAPartlyPaidInvoiceRecordsThePaymentWithoutReducingTheClaim(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0),
            $this->bankStatement(2, [
                ['amount' => 4000.0, 'currency' => 'RON', 'date' => '2025-04-10', 'invoiceReferences' => ['MJ-9/2025']],
            ]),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertFalse($row->excluded);
        self::assertSame(4000.0, $row->paidAmount);
        self::assertSame(10000.0, $row->amount);
        self::assertSame(10000.0, $row->amountRon);
        self::assertContains(self::PAID_IN_PART, $row->warningKeys);
        self::assertNotContains(self::PAID_IN_FULL, $row->warningKeys);
        self::assertTrue($row->requiresIndividualConfirmation(0.8));
        self::assertSame([], $result->conflicts);
    }

    public function testTheSamePaymentInBothStatementsIsCountedOnce(): void
    {
        $payment = ['amount' => 4000.0, 'currency' => 'RON', 'date' => '2025-04-10', 'invoiceReferences' => ['MJ-9/2025']];
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 8000.0),
            $this->accountStatement(2, [$payment]),
            $this->bankStatement(3, [$payment]),
        ])->collectRows([1, 2, 3]);

        $row = $this->row($result->rows, 'MJ-9/2025');
        self::assertSame(4000.0, $row->paidAmount);
        self::assertFalse($row->excluded);
    }

    public function testAPaymentForSeveralInvoicesIsSplitOnlyWhenItPaysExactlyTheirTotal(): void
    {
        $exact = $this->factory([
            $this->invoice(1, 'FF 1001', 1000.0),
            $this->invoice(2, 'FF 1002', 2000.0),
            $this->bankStatement(3, [
                ['amount' => 3000.0, 'currency' => 'RON', 'date' => '2025-04-10', 'invoiceReferences' => ['FF1001', 'FF1002']],
            ]),
        ])->collectRows([1, 2, 3]);
        self::assertTrue($this->row($exact->rows, 'FF 1001')->excluded);
        self::assertTrue($this->row($exact->rows, 'FF 1002')->excluded);

        $partial = $this->factory([
            $this->invoice(1, 'FF 1001', 1000.0),
            $this->invoice(2, 'FF 1002', 2000.0),
            $this->bankStatement(3, [
                ['amount' => 1500.0, 'currency' => 'RON', 'date' => '2025-04-10', 'invoiceReferences' => ['FF1001', 'FF1002']],
            ]),
        ])->collectRows([1, 2, 3]);
        $row = $this->row($partial->rows, 'FF 1001');
        self::assertFalse($row->excluded);
        self::assertSame(0.0, $row->paidAmount, 'an unsplit payment is not attributed to either invoice');
        self::assertTrue($row->statementPayments[0]['shared']);
        self::assertContains(self::PAID_IN_PART, $row->warningKeys);
        self::assertSame([], $partial->conflicts);
    }

    public function testAPaymentAlsoNamingInvoicesOutsideTheFileIsNotCountedOnThePositionItNames(): void
    {
        // The account statement of Dosar 3: each transfer of 30.12.2025 lists
        // three invoices, only one of which is claimed.
        $result = $this->factory([
            $this->invoice(1, '23381', 33666.0),
            $this->accountStatement(2, [
                ['amount' => 68448.0, 'currency' => 'RON', 'date' => '2025-12-30', 'invoiceReferences' => ['20954', '23275', '23381']],
                ['amount' => 33209.4, 'currency' => 'RON', 'date' => '2025-12-30', 'invoiceReferences' => ['20954', '23275', '23381']],
            ]),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertFalse($row->excluded);
        self::assertSame(0.0, $row->paidAmount);
        self::assertCount(2, $row->statementPayments);
        self::assertTrue($row->statementPayments[0]['shared']);
        self::assertContains('wizard.step3.claim_items.warning.payment_in_statement', $row->warningKeys);
    }

    public function testAPaymentNamingNoPositionRaisesTheUnmatchedWarning(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0),
            $this->bankStatement(2, [
                ['amount' => 4000.0, 'currency' => 'RON', 'date' => '2025-04-10', 'invoiceReferences' => ['MJ-9/2025']],
                ['amount' => 900.0, 'currency' => 'RON', 'date' => '2025-04-11'],
            ]),
        ])->collectRows([1, 2]);

        self::assertSame(4000.0, $result->rows[0]->paidAmount);
        self::assertCount(1, $result->conflicts);
        self::assertSame(self::UNMATCHED, $result->conflicts[0]->messageKey);
    }

    public function testANumberSharingOnlyItsLastDigitsWithAnInvoiceDoesNotMatchIt(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'LDT 20951', 5000.0),
            $this->accountStatement(2, [
                ['invoiceReferences' => ['120951'], 'fullyPaid' => true],
            ]),
        ])->collectRows([1, 2]);

        self::assertFalse($result->rows[0]->excluded);
        self::assertSame(self::UNMATCHED, $result->conflicts[0]->messageKey);
    }

    public function testABankStatementExtractedBeforePaymentsWereListedFallsBackToItsText(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0),
            $this->document(2, DocumentType::EXTRAS_CONT, [
                'description' => 'incasare 10000 RON contravaloare fact. MJ-9/2025',
            ]),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertFalse($row->excluded, 'free text is never enough to take an invoice out of the claim');
        self::assertSame(0.0, $row->paidAmount);
        self::assertContains(self::PAID_IN_PART, $row->warningKeys);
        self::assertSame([], $result->conflicts);
    }

    public function testABankStatementStoredWithANullPaymentListFallsBackToItsText(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0),
            $this->document(2, DocumentType::EXTRAS_CONT, [
                'description' => 'incasare 10000 RON contravaloare fact. MJ-9/2025',
                'payments' => null,
            ]),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertFalse($row->excluded);
        self::assertContains(self::PAID_IN_PART, $row->warningKeys);
    }

    public function testABankStatementWithAnEmptyPaymentListDoesNotFallBackToItsText(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0),
            $this->document(2, DocumentType::EXTRAS_CONT, [
                'description' => 'incasare 10000 RON contravaloare fact. MJ-9/2025',
                'payments' => [],
            ]),
        ])->collectRows([1, 2]);

        self::assertSame([], $result->rows[0]->warningKeys);
    }

    public function testABalanceConfirmationWithoutPaymentsChangesNoPosition(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0),
            $this->document(2, DocumentType::CONFIRMARE_SOLD, [
                'amount' => 10000.0,
                'currency' => 'RON',
                'description' => 'Sold confirmat la 31.12.2025',
                'payments' => [],
            ]),
        ])->collectRows([1, 2]);

        $row = $this->row($result->rows, 'MJ-9/2025');
        self::assertFalse($row->excluded);
        self::assertSame([], $row->warningKeys);
        self::assertSame([], $result->conflicts);
    }

    /**
     * @param list<ClaimItemRow> $rows
     */
    private function row(array $rows, string $number): ClaimItemRow
    {
        foreach ($rows as $row) {
            if ($row->documentNumber === $number) {
                return $row;
            }
        }
        self::fail('No position for ' . $number);
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

    private function invoice(int $id, string $number, float $amount, string $currency = 'RON'): Document
    {
        return $this->document($id, DocumentType::FACTURA, [
            'amount' => $amount,
            'currency' => $currency,
            'invoiceNumber' => $number,
            'invoiceDate' => '2025-03-01',
            'dueDate' => '2025-04-01',
            'confidencePerField' => ['amount' => 0.95],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $payments
     */
    private function accountStatement(int $id, array $payments): Document
    {
        return $this->document($id, DocumentType::CONFIRMARE_SOLD, ['payments' => $payments]);
    }

    /**
     * @param list<array<string, mixed>> $payments
     */
    private function bankStatement(int $id, array $payments): Document
    {
        return $this->document($id, DocumentType::EXTRAS_CONT, ['payments' => $payments]);
    }

    /**
     * @param array<string, mixed> $claim
     */
    private function document(int $id, DocumentType $type, array $claim): Document
    {
        $document = new Document();
        $document->setDocumentType($type);
        $document->setExtractedData([
            'schemaVersion' => 2,
            'sourceDocumentId' => $id,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'claim' => $claim,
        ]);
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }
}
