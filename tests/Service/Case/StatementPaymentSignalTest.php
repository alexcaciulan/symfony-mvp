<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\BnrExchangeRate;
use App\Entity\Document;
use App\Enum\ConflictSeverity;
use App\Enum\DocumentType;
use App\Repository\BnrExchangeRateRepository;
use App\Repository\DocumentRepository;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Case\ClaimItemFactory;
use PHPUnit\Framework\TestCase;

/**
 * A payment listed only on the bank statement used to disappear.
 *
 * The statement carries no claim of its own, so it produces no position, and
 * the invoice still states its full total, so nothing on the table said that
 * part of it had been received. Imputation is the lawyer's (Civil Code art.
 * 1507-1509), which it cannot be if they are never shown the payment.
 */
final class StatementPaymentSignalTest extends TestCase
{
    public function testAPaymentReferencingAnInvoiceMarksThatPosition(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0),
            $this->statement(2, 'incasare 4000 RON contravaloare fact. MJ-9/2025'),
        ])->collectRows([1, 2]);

        self::assertCount(1, $result->rows);
        $row = $result->rows[0];
        self::assertSame(10000.0, $row->amount, 'the sum claimed stays the invoiced total');
        self::assertContains('wizard.step3.claim_items.warning.payment_in_statement', $row->warningKeys);
        self::assertTrue($row->requiresIndividualConfirmation(0.8));
    }

    public function testAPaymentTiedToNoPositionIsRaisedAsAConflict(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0),
            $this->statement(2, 'incasare 4000 RON contravaloare fact. ZZ-77/2024'),
        ])->collectRows([1, 2]);

        self::assertSame([], $result->rows[0]->warningKeys);
        $conflict = $result->conflicts[0];
        self::assertSame('wizard.conflict.claim_item.payment_unmatched', $conflict->messageKey);
        self::assertSame(ConflictSeverity::WARNING, $conflict->severity);
    }

    public function testAStatementWithoutIncomingPaymentsChangesNothing(): void
    {
        $result = $this->factory([
            $this->invoice(1, 'MJ-9/2025', 10000.0),
            $this->statement(2, 'sold final de cont la 31.12.2025'),
        ])->collectRows([1, 2]);

        self::assertSame([], $result->rows[0]->warningKeys);
        self::assertSame([], $result->conflicts);
    }

    /**
     * @param list<Document> $documents
     */
    private function factory(array $documents): ClaimItemFactory
    {
        $repository = $this->createStub(DocumentRepository::class);
        $repository->method('findBy')->willReturn($documents);

        return new ClaimItemFactory($repository, $this->converter());
    }

    private function converter(): CurrencyConverter
    {
        $rates = $this->createStub(BnrExchangeRateRepository::class);
        $rates->method('findRateValidAt')->willReturn(new BnrExchangeRate());

        return new CurrencyConverter($rates);
    }

    private function invoice(int $id, string $number, float $amount): Document
    {
        return $this->document($id, DocumentType::FACTURA, [
            'amount' => $amount,
            'currency' => 'RON',
            'invoiceNumber' => $number,
            'invoiceDate' => '2025-03-01',
            'dueDate' => '2025-04-01',
            'confidencePerField' => ['amount' => 0.95],
        ]);
    }

    private function statement(int $id, string $description): Document
    {
        return $this->document($id, DocumentType::EXTRAS_CONT, [
            'description' => $description,
            'confidencePerField' => ['description' => 0.9],
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
