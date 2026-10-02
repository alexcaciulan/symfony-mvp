<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\BnrExchangeRate;
use App\Entity\Document;
use App\Enum\ClaimItemKind;
use App\Enum\DocumentType;
use App\Repository\BnrExchangeRateRepository;
use App\Repository\DocumentRepository;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Case\ClaimItemFactory;
use PHPUnit\Framework\TestCase;

/**
 * An advance invoice and the final invoice that reverses it, as in case 6 of
 * the August 2026 lawyer review (Bluebox Medical v Creative & Innovative
 * Management). The final invoice was read as a storno, so the claim came out
 * as the advance minus the final invoice: a negative principal.
 */
final class AdvanceInvoiceSettlementTest extends TestCase
{
    private const FINAL_DESCRIPTION = 'Factura cuprinde echipamente medicale (analizoare) și o linie de storno avans cf BMI2022730/05/12/2023 în valoare de -61811.64 fara TVA.';

    public function testAFinalInvoiceReversingTheAdvanceIsAnInvoiceNotAStorno(): void
    {
        $result = $this->factory([
            $this->invoice(55, 'BMI2022943', 416855.99, '2024-03-23', self::FINAL_DESCRIPTION),
        ])->collectRows([55]);

        $row = $result->rows[0];
        self::assertSame(ClaimItemKind::INVOICE, $row->kind);
        self::assertSame(416855.99, $row->amount);
        self::assertContains('wizard.step3.claim_items.warning.final_invoice_settles_advance', $row->warningKeys);
        self::assertNotContains('wizard.step3.claim_items.warning.credit_note', $row->warningKeys);
    }

    public function testTheAdvanceTheFinalInvoiceReversesLeavesTheClaim(): void
    {
        $result = $this->factory([
            $this->invoice(54, 'BMI2022730', 73555.85, '2023-12-05', 'Factura avans 15% conform contract 11317/14.11.2023.'),
            $this->invoice(55, 'BMI2022943', 416855.99, '2024-03-23', self::FINAL_DESCRIPTION),
        ])->collectRows([54, 55]);

        [$advance, $final] = $result->rows;
        self::assertTrue($advance->excluded, 'claiming it again asks for a paid advance twice');
        self::assertContains('wizard.step3.claim_items.warning.advance_settled_by_final', $advance->warningKeys);
        self::assertFalse($final->excluded);

        $principal = array_sum(array_map(static fn ($r) => $r->willCount() ? $r->signedAmountRon() : 0.0, $result->rows));
        self::assertSame(416855.99, round($principal, 2));
    }

    public function testAnAdvanceNotNamedByTheFinalInvoiceStays(): void
    {
        $result = $this->factory([
            $this->invoice(54, 'BMI2099999', 1000.0, '2023-12-05', 'Factura avans 15%.'),
            $this->invoice(55, 'BMI2022943', 416855.99, '2024-03-23', self::FINAL_DESCRIPTION),
        ])->collectRows([54, 55]);

        self::assertFalse($result->rows[0]->excluded);
    }

    public function testARealStornoStaysACreditNote(): void
    {
        $result = $this->factory([
            $this->invoice(60, 'ST-12', 1200.0, '2024-01-10', 'Factura storno integrală a facturii FF-11.'),
            $this->invoice(61, 'ST-13', -500.0, '2024-01-11', 'Storno avans cf FF-10.'),
        ])->collectRows([60, 61]);

        self::assertSame(ClaimItemKind::CREDIT_NOTE, $result->rows[0]->kind);
        self::assertSame(ClaimItemKind::CREDIT_NOTE, $result->rows[1]->kind, 'a negative total is a storno whatever it reverses');
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

    private function invoice(int $id, string $number, float $amount, string $dueDate, string $description): Document
    {
        $document = new Document();
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setExtractedData([
            'schemaVersion' => 2,
            'sourceDocumentId' => $id,
            'strategy' => 'ai_vision',
            'globalConfidence' => 0.9,
            'claim' => [
                'amount' => $amount,
                'currency' => 'RON',
                'invoiceNumber' => $number,
                'invoiceDate' => $dueDate,
                'dueDate' => $dueDate,
                'description' => $description,
                'confidencePerField' => ['amount' => 0.95],
            ],
        ]);
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, $id);

        return $document;
    }
}
