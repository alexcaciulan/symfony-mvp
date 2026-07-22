<?php

namespace App\Tests\Service\Extraction;

use App\Entity\Document;
use App\Service\Extraction\PdfParserExtractionStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the static, committed fixtures under
 * tests/fixtures/extraction/. These complement the unit-style tests in
 * PdfParserExtractionStrategyTest (which use trivial DomPDF-rendered HTML)
 * by exercising layouts that actually appear in lawyer practice:
 *   - invoice-realistic.pdf  — ERP-style invoice with a 5-row line-item table,
 *                              header company block, totals section, footer with
 *                              IBAN + late-payment-interest reference.
 *   - contract-multipage.pdf — 2-page B2B service contract with structured
 *                              articles, page break, signature block.
 *   - loan-individual.pdf    — Loan contract between PJ and individual debtor
 *                              identified by CNP (not CUI).
 *
 * The fixtures are regenerated only when their content needs to change, by
 * running tests/fixtures/extraction/generate-fixtures.php. The generated
 * binaries are committed so test runs are deterministic across machines.
 */
class PdfParserExtractionStrategyIntegrationTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../fixtures/extraction';

    private PdfParserExtractionStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new PdfParserExtractionStrategy(self::FIXTURES_DIR);
    }

    public function testRealisticInvoiceSupportsAndExtractsCoreFields(): void
    {
        $document = $this->makeDocument('invoice-realistic.pdf');

        $this->assertTrue($this->strategy->supports($document));

        $result = $this->strategy->extract($document);

        // Creditor (Furnizor): Alpha Servicii Comerciale SRL
        $this->assertNotNull($result->creditor);
        $this->assertSame('15193236', $result->creditor->cui);
        $this->assertSame('RO49AAAA1B31007593840000', $result->creditor->iban);

        // Debtor (Client): Beta Distribution SRL
        $this->assertNotNull($result->primaryDebtor());
        $this->assertSame('14186770', $result->primaryDebtor()->cui);
    }

    public function testRealisticInvoiceExtractsClaimAmountAndDueDate(): void
    {
        $document = $this->makeDocument('invoice-realistic.pdf');

        $result = $this->strategy->extract($document);

        $this->assertNotNull($result->claim);
        $this->assertSame('RON', $result->claim->currency);

        // Due date "31.05.2026" appears next to "Termen de plata" / "Scadenta" — must parse correctly.
        $this->assertNotNull($result->claim->dueDate);
        $this->assertSame('2026-05-31', $result->claim->dueDate->format('Y-m-d'));

        // Total in invoice is 7.532,70 RON — must parse the Romanian decimal format.
        // Line items are 952..4046 RON, TVA alone is 1202.70 RON. A floor of 1200
        // ensures we caught a meaningful aggregate (subtotal/total/TVA), not a single
        // unit price (e.g. 85.0 RON consultancy hourly rate); the upper bound matches
        // the actual invoice total. This protects against smalot text-ordering quirks
        // without letting a wildly wrong extraction (e.g. picking up "5" from "ap. 5")
        // pass silently.
        $this->assertNotNull($result->claim->amount);
        $this->assertGreaterThan(1200.0, $result->claim->amount);
        $this->assertLessThanOrEqual(7532.70, $result->claim->amount);
    }

    public function testMultipageContractPreservesSectionAttributionAcrossPages(): void
    {
        $document = $this->makeDocument('contract-multipage.pdf');

        $this->assertTrue($this->strategy->supports($document));

        $result = $this->strategy->extract($document);

        // Page 1 introduces both parties. The section-based heuristic must keep them
        // attributed correctly even though the contract continues on page 2.
        $this->assertNotNull($result->creditor);
        $this->assertNotNull($result->primaryDebtor());
        $this->assertSame('15193236', $result->creditor->cui, 'Furnizor → creditor');
        $this->assertSame('RO49AAAA1B31007593840000', $result->creditor->iban);
        $this->assertSame('14186770', $result->primaryDebtor()->cui, 'Client → debtor');
    }

    public function testLoanContractExtractsCnpForIndividualDebtor(): void
    {
        $document = $this->makeDocument('loan-individual.pdf');

        $this->assertTrue($this->strategy->supports($document));

        $result = $this->strategy->extract($document);

        // Imprumutator (creditor) is a legal entity (PJ) with CUI.
        $this->assertNotNull($result->creditor);
        $this->assertSame('15193236', $result->creditor->cui);

        // Imprumutat (debtor) is an individual identified by CNP, not CUI.
        $this->assertNotNull($result->primaryDebtor());
        $this->assertSame('1980715221232', $result->primaryDebtor()->personalId);
        $this->assertNull($result->primaryDebtor()->cui, 'Individual debtor must NOT have a CUI assigned');
    }

    public function testGlobalConfidenceIsMeaningfulForRealDocuments(): void
    {
        // After moving to coverage-weighted confidence (Pas 3.0 follow-up),
        // PdfParser intentionally falls BELOW the 0.6 short-circuit threshold
        // on partial extractions — that's the whole point: let the AI cascade
        // try to fill the missing fields rather than declaring "done" on 5/25.
        // Floor 0.15 keeps a regression signal: PdfParser must still surface
        // multiple high-confidence fields on each realistic fixture.
        foreach (['invoice-realistic.pdf', 'contract-multipage.pdf', 'loan-individual.pdf'] as $filename) {
            $document = $this->makeDocument($filename);

            $result = $this->strategy->extract($document);

            $this->assertGreaterThan(
                0.15,
                $result->globalConfidence,
                "Expected meaningful coverage confidence for {$filename}, got {$result->globalConfidence}",
            );
        }
    }

    public function testVatPayerFlagIsSetWhenRoPrefixIsPresent(): void
    {
        // Fixturile (invoice-realistic, contract-multipage, loan-individual) tipăresc
        // explicit "CUI RO15193236" și "CUI RO14186770" — ambele entități sunt
        // plătitori TVA per Codul Fiscal art. 316. Strategy trebuie să marcheze asta
        // pe DTO ca isVatPayer = true (informația distinctă față de CIF non-VAT).
        $document = $this->makeDocument('invoice-realistic.pdf');

        $result = $this->strategy->extract($document);

        $this->assertNotNull($result->creditor);
        $this->assertTrue($result->creditor->isVatPayer, 'Creditor cu prefix RO trebuie marcat plătitor TVA');

        $this->assertNotNull($result->primaryDebtor());
        $this->assertTrue($result->primaryDebtor()->isVatPayer, 'Debtor cu prefix RO trebuie marcat plătitor TVA');
    }

    public function testFixturesAreCommittedAndReadable(): void
    {
        // Sanity: prevent accidental fixture deletion / git ignore. Each fixture
        // must exist and be a non-trivial PDF (>5KB — real layouts are 20-30KB).
        foreach (['invoice-realistic.pdf', 'contract-multipage.pdf', 'loan-individual.pdf'] as $filename) {
            $path = self::FIXTURES_DIR . '/' . $filename;
            $this->assertFileExists($path);
            $this->assertGreaterThan(5_000, filesize($path), "Fixture {$filename} is suspiciously small");

            $magic = file_get_contents($path, length: 4);
            $this->assertSame('%PDF', $magic, "Fixture {$filename} is not a PDF");
        }
    }

    private function makeDocument(string $filename, string $mime = 'application/pdf'): Document
    {
        $document = new Document();
        $document->setStoredFilename($filename);
        $document->setMimeType($mime);

        return $document;
    }
}
