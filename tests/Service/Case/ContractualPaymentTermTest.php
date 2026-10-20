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
use App\Service\Case\ContractualPaymentTermApplier;
use App\Service\Deadline\WorkingDayResolver;
use PHPUnit\Framework\TestCase;

/**
 * The payment term of the contract prevails over the due date printed on the
 * invoice: invoices are often issued ignoring the contract, and the contract is
 * what binds the debtor.
 */
final class ContractualPaymentTermTest extends TestCase
{
    public function testATermFromTheIssueDateOverridesThePrintedDueDate(): void
    {
        // The invoice is printed as due the day it was issued; the contract
        // grants four days from invoicing.
        $result = $this->factory([
            $this->contract(1, '12/2024', 4, 'ISSUE_DATE'),
            $this->invoice(2, 'MJ-9/2025', '2025-03-01', '2025-03-01', 'Contract nr. 12/2024'),
        ])->collectRows([1, 2]);

        self::assertCount(1, $result->rows, 'a contract produces no position of its own');
        $row = $result->rows[0];
        self::assertSame('2025-03-05', $row->dueDate?->format('Y-m-d'));
        self::assertSame([
            'key' => 'wizard.step3.claim_items.note.contract_term_overrides_invoice',
            'days' => 4,
            'anchor' => 'ISSUE_DATE',
            'printedDueDate' => '2025-03-01',
            'appliedDueDate' => '2025-03-05',
        ], $row->dueDateNote);
    }

    public function testAPrintedDueDateThatMatchesTheContractIsExplainedNotOverridden(): void
    {
        $result = $this->factory([
            $this->contract(1, '12/2024', 4, 'ISSUE_DATE'),
            $this->invoice(2, 'MJ-9/2025', '2025-03-01', '2025-03-05', '12/2024'),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertSame('2025-03-05', $row->dueDate?->format('Y-m-d'));
        self::assertSame('wizard.step3.claim_items.note.contract_term_applied', $row->dueDateNote['key'] ?? null);
    }

    public function testALongerTermPrintedOnTheInvoiceIsKept(): void
    {
        $result = $this->factory([
            $this->contract(1, '12/2024', 4, 'ISSUE_DATE'),
            $this->invoice(2, 'MJ-9/2025', '2025-03-01', '2025-03-31', '12/2024'),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertSame('2025-03-31', $row->dueDate?->format('Y-m-d'));
        self::assertSame('wizard.step3.claim_items.note.contract_term_longer_on_invoice', $row->dueDateNote['key'] ?? null);
        self::assertSame('2025-03-31', $row->dueDateNote['appliedDueDate'] ?? null);
    }

    public function testAnInvoiceCitingNoContractTakesTheTermOfTheOnlyContractInTheFile(): void
    {
        $result = $this->factory([
            $this->contract(1, '12/2024', 4, 'ISSUE_DATE'),
            $this->invoice(2, 'MJ-9/2025', '2025-03-01', null, null),
        ])->collectRows([1, 2]);

        self::assertSame('2025-03-05', $result->rows[0]->dueDate?->format('Y-m-d'));
        self::assertSame('wizard.step3.claim_items.note.contract_term_applied', $result->rows[0]->dueDateNote['key'] ?? null);
    }

    public function testAnInvoiceCitingAnotherContractKeepsItsPrintedDueDate(): void
    {
        $result = $this->factory([
            $this->contract(1, '12/2024', 4, 'ISSUE_DATE'),
            $this->invoice(2, 'MJ-9/2025', '2025-03-01', '2025-03-31', 'Contract 77/2023'),
        ])->collectRows([1, 2]);

        self::assertSame('2025-03-31', $result->rows[0]->dueDate?->format('Y-m-d'));
        self::assertNull($result->rows[0]->dueDateNote);
    }

    public function testATermFromAnotherEventIsLeftToTheLawyer(): void
    {
        $result = $this->factory([
            $this->contract(1, '12/2024', 30, 'DELIVERY'),
            $this->invoice(2, 'MJ-9/2025', '2025-03-01', '2025-03-01', '12/2024'),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertSame('2025-03-01', $row->dueDate?->format('Y-m-d'), 'the printed date is not recomputed');
        self::assertSame('wizard.step3.claim_items.note.contract_term_manual', $row->dueDateNote['key'] ?? null);
        self::assertSame('DELIVERY', $row->dueDateNote['anchor'] ?? null);
        self::assertSame(30, $row->dueDateNote['days'] ?? null);
        self::assertNull($row->dueDateNote['appliedDueDate'] ?? null);
    }

    public function testATermInWorkingDaysFromTheIssueDateSkipsTheWeekend(): void
    {
        // Issued on a Saturday: five working days run Monday 3 to Friday 7 March.
        $result = $this->factory([
            $this->contract(1, '12/2024', 5, 'ISSUE_DATE', businessDays: true),
            $this->invoice(2, 'MJ-9/2025', '2025-03-01', '2025-03-01', '12/2024'),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertSame('2025-03-07', $row->dueDate?->format('Y-m-d'));
        self::assertSame([
            'key' => 'wizard.step3.claim_items.note.contract_term_overrides_invoice_business_days',
            'days' => 5,
            'anchor' => 'ISSUE_DATE',
            'printedDueDate' => '2025-03-01',
            'appliedDueDate' => '2025-03-07',
        ], $row->dueDateNote);
    }

    public function testATermInWorkingDaysSkipsLegalHolidays(): void
    {
        // Thursday 17 April 2025: Good Friday, the Easter weekend and Easter
        // Monday do not count, so three working days end on Thursday 24 April.
        $result = $this->factory([
            $this->contract(1, '12/2024', 3, 'ISSUE_DATE', businessDays: true),
            $this->invoice(2, 'MJ-9/2025', '2025-04-17', null, '12/2024'),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertSame('2025-04-24', $row->dueDate?->format('Y-m-d'));
        self::assertSame('wizard.step3.claim_items.note.contract_term_applied_business_days', $row->dueDateNote['key'] ?? null);
    }

    public function testALongerPrintedTermBeatsAWorkingDayTerm(): void
    {
        $result = $this->factory([
            $this->contract(1, '12/2024', 5, 'ISSUE_DATE', businessDays: true),
            $this->invoice(2, 'MJ-9/2025', '2025-03-01', '2025-03-31', '12/2024'),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertSame('2025-03-31', $row->dueDate?->format('Y-m-d'));
        self::assertSame('wizard.step3.claim_items.note.contract_term_longer_on_invoice_business_days', $row->dueDateNote['key'] ?? null);
    }

    public function testATermInWorkingDaysFromAnotherEventIsLeftToTheLawyer(): void
    {
        $result = $this->factory([
            $this->contract(1, '12/2024', 5, 'INVOICE_RECEIPT', businessDays: true),
            $this->invoice(2, 'MJ-9/2025', '2025-03-01', '2025-03-01', '12/2024'),
        ])->collectRows([1, 2]);

        $row = $result->rows[0];
        self::assertSame('2025-03-01', $row->dueDate?->format('Y-m-d'));
        self::assertSame('wizard.step3.claim_items.note.contract_term_manual_business_days', $row->dueDateNote['key'] ?? null);
        self::assertNull($row->dueDateNote['appliedDueDate'] ?? null);
    }

    public function testDivergingTermsForOneContractAreNotApplied(): void
    {
        $result = $this->factory([
            $this->contract(1, '12/2024', 4, 'ISSUE_DATE'),
            $this->contract(2, '12/2024', 15, 'ISSUE_DATE', DocumentType::ACT_ADITIONAL),
            $this->invoice(3, 'MJ-9/2025', '2025-03-01', '2025-03-01', '12/2024'),
        ])->collectRows([1, 2, 3]);

        $row = $result->rows[0];
        self::assertSame('2025-03-01', $row->dueDate?->format('Y-m-d'));
        self::assertSame('wizard.step3.claim_items.note.contract_term_ambiguous', $row->dueDateNote['key'] ?? null);
    }

    public function testAContractExtractedBeforeTheTermExistedChangesNothing(): void
    {
        $result = $this->factory([
            $this->document(1, DocumentType::CONTRACT, ['contractNumber' => '12/2024', 'description' => 'plata în 4 zile de la facturare']),
            $this->invoice(2, 'MJ-9/2025', '2025-03-01', '2025-03-01', '12/2024'),
        ])->collectRows([1, 2]);

        self::assertSame('2025-03-01', $result->rows[0]->dueDate?->format('Y-m-d'));
        self::assertNull($result->rows[0]->dueDateNote);
    }

    public function testAnInvoiceWithoutAnyTermKeepsTheCurrentBehaviour(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', '2025-03-01', null, null),
        ])->collectRows([1]);

        self::assertNull($result->rows[0]->dueDate);
        self::assertNull($result->rows[0]->dueDateNote);
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

        return new ClaimItemFactory($repository, new CurrencyConverter($rates), paymentTerms: new ContractualPaymentTermApplier(new WorkingDayResolver()));
    }

    private function contract(
        int $id,
        string $number,
        int $days,
        string $anchor,
        DocumentType $type = DocumentType::CONTRACT,
        bool $businessDays = false,
    ): Document {
        return $this->document($id, $type, [
            'contractNumber' => $number,
            'paymentTermDays' => $days,
            'paymentTermAnchor' => $anchor,
            'paymentTermBusinessDays' => $businessDays,
        ]);
    }

    private function invoice(int $id, string $number, string $date, ?string $dueDate, ?string $contract): Document
    {
        return $this->document($id, DocumentType::FACTURA, [
            'amount' => 1000.0,
            'currency' => 'RON',
            'invoiceNumber' => $number,
            'invoiceDate' => $date,
            'dueDate' => $dueDate,
            'contractNumber' => $contract,
            'confidencePerField' => ['amount' => 0.95],
        ]);
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
