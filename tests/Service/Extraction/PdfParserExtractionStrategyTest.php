<?php

namespace App\Tests\Service\Extraction;

use App\Entity\Document;
use App\Service\Extraction\PdfParserExtractionStrategy;
use Dompdf\Dompdf;
use PHPUnit\Framework\TestCase;

class PdfParserExtractionStrategyTest extends TestCase
{
    private static string $fixturesDir;

    private PdfParserExtractionStrategy $strategy;

    public static function setUpBeforeClass(): void
    {
        self::$fixturesDir = sys_get_temp_dir() . '/lexrecovery-pdf-fixtures-' . uniqid();
        mkdir(self::$fixturesDir, 0o755, true);

        // CUI valid: 15193236 (creditor) + 14186770 (debtor — Banca Transilvania, public)
        // CNP valid: 1900101220018
        // IBAN valid: RO49AAAA1B31007593840000 (ISO 7064 reference example)
        self::generatePdf('contract.pdf', '
            <h1>Contract de prestari servicii nr. 42 din 01.05.2026</h1>
            <h2>I. Partile</h2>
            <p><b>Furnizor:</b> SC Foo Servicii SRL,
               cu sediul in Cluj-Napoca, str. Memorandumului nr. 5,
               CUI RO15193236,
               cont IBAN RO49AAAA1B31007593840000,
               reprezentat de Ion Popescu in calitate de administrator.</p>
            <p><b>Client:</b> SC Bar Distribution SRL,
               cu sediul in Bucuresti, sector 3,
               CUI RO14186770.</p>
            <h2>II. Obiectul si pretul</h2>
            <p>Suma datorata: 5.000,00 RON, plata efectuata prin transfer bancar.</p>
            <p>Termen plata: 15.06.2026.</p>
        ');

        self::generatePdf('factura.pdf', '
            <h1>Factura fiscala nr. INV-2026-100</h1>
            <p><b>Furnizor:</b> SC Alpha SRL, CUI RO15193236, IBAN RO49AAAA1B31007593840000</p>
            <p><b>Client:</b> SC Beta SRL, CUI RO14186770</p>
            <p>Total de plata: 1.250,75 RON</p>
            <p>Scadenta factura: 30.06.2026</p>
        ');

        self::generatePdf('text-minimal.pdf', '<p>X</p>');

        // Same content as contract, but with deliberately invalid CUI for the creditor.
        self::generatePdf('contract-invalid-cui.pdf', '
            <p><b>Furnizor:</b> SC Test SRL, CUI RO12345678, IBAN RO49AAAA1B31007593840000</p>
            <p><b>Client:</b> SC Cumparator SRL, CUI RO14186770</p>
            <p>Suma datorata: 1.000,00 RON</p>
            <p>Termen plata: 01.07.2026</p>
        ');

        self::generatePdf('contract-invalid-cnp.pdf', '
            <p><b>Furnizor:</b> SC Test SRL, CUI RO15193236, IBAN RO49AAAA1B31007593840000</p>
            <p><b>Debitor:</b> Persoana fizica avand CNP 1234567890123.</p>
            <p>Suma datorata: 800,00 RON</p>
            <p>Termen plata: 10.06.2026</p>
        ');

        // CNP 1980715221232 — fictive, valid against the OFFICIAL weights per OUG 97/2005:
        //   weights = [2,7,9,1,4,6,3,5,8,2,7,9] → sum = 255, mod 11 = 2 = check digit ✓
        // Deliberately chosen to differ from a previous bug-state weight set
        // [2,7,5,7,9,1,3,5,7,2,4,6,8] (where the first 12 values were:
        // [2,7,5,7,9,1,3,5,7,2,4,6] — sum = 236, mod 11 = 5 ≠ 2 → INVALID).
        // This makes the test a genuine regression guard against weight-set drift.
        self::generatePdf('contract-with-cnp.pdf', '
            <p><b>Furnizor:</b> SC Test SRL, CUI RO15193236, IBAN RO49AAAA1B31007593840000</p>
            <p><b>Debitor:</b> Persoana fizica avand CNP 1980715221232.</p>
            <p>Suma datorata: 800,00 RON</p>
            <p>Termen plata: 10.06.2026</p>
        ');

        // Corrupt "PDF" — smalot must throw, supports() must return false.
        file_put_contents(self::$fixturesDir . '/corrupt.pdf', 'NOT A PDF AT ALL');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (glob(self::$fixturesDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$fixturesDir);
    }

    protected function setUp(): void
    {
        $this->strategy = new PdfParserExtractionStrategy(self::$fixturesDir);
    }

    public function testPriorityIs100AndStrategyKeyIsPdfParser(): void
    {
        $this->assertSame(100, $this->strategy->priority());
        $this->assertSame(100, PdfParserExtractionStrategy::PRIORITY);
        $this->assertSame('pdf_parser', PdfParserExtractionStrategy::STRATEGY_KEY);
    }

    public function testIsAiBackedFalse(): void
    {
        $this->assertFalse($this->strategy->isAiBacked());
    }

    public function testSupportsReturnsTrueForTextPdf(): void
    {
        $document = $this->makeDocument('contract.pdf');

        $this->assertTrue($this->strategy->supports($document));
    }

    public function testSupportsReturnsFalseForNonPdfMime(): void
    {
        $document = $this->makeDocument('contract.pdf', mime: 'image/png');

        $this->assertFalse($this->strategy->supports($document));
    }

    public function testSupportsReturnsFalseForCorruptPdf(): void
    {
        $document = $this->makeDocument('corrupt.pdf');

        $this->assertFalse($this->strategy->supports($document));
    }

    public function testSupportsReturnsFalseForPdfWithInsufficientText(): void
    {
        // text-minimal.pdf has body "<p>X</p>" — well below MIN_TEXT_LENGTH (100 chars).
        // This is the proxy for scanned PDFs without a usable text layer:
        // the cascade should pass over PdfParser and let later strategies (OCR at 2.5.7) try.
        $document = $this->makeDocument('text-minimal.pdf');

        $this->assertFalse($this->strategy->supports($document));
    }

    public function testExtractsCuiAndIbanFromContract(): void
    {
        $document = $this->makeDocument('contract.pdf');

        $result = $this->strategy->extract($document);

        $this->assertNotNull($result->creditor);
        $this->assertSame('15193236', $result->creditor->cui);
        $this->assertSame('RO49AAAA1B31007593840000', $result->creditor->iban);

        $this->assertNotNull($result->debtor);
        $this->assertSame('14186770', $result->debtor->cui);
    }

    public function testExtractsAmountAndDueDateFromContract(): void
    {
        $document = $this->makeDocument('contract.pdf');

        $result = $this->strategy->extract($document);

        $this->assertNotNull($result->claim);
        $this->assertSame(5000.00, $result->claim->amount);
        $this->assertSame('RON', $result->claim->currency);
        $this->assertNotNull($result->claim->dueDate);
        $this->assertSame('2026-06-15', $result->claim->dueDate->format('Y-m-d'));
    }

    public function testExtractsCreditorVsDebtorByContextualKeywords(): void
    {
        $document = $this->makeDocument('contract.pdf');

        $result = $this->strategy->extract($document);

        $this->assertNotNull($result->creditor);
        $this->assertNotNull($result->debtor);
        $this->assertNotSame($result->creditor->cui, $result->debtor->cui, 'Creditor and debtor CUIs must differ');
        $this->assertSame('15193236', $result->creditor->cui, 'Creditor CUI = furnizor');
        $this->assertSame('14186770', $result->debtor->cui, 'Debtor CUI = client');
    }

    public function testExtractsCnpFromDebtor(): void
    {
        $document = $this->makeDocument('contract-with-cnp.pdf');

        $result = $this->strategy->extract($document);

        $this->assertNotNull($result->debtor);
        $this->assertSame('1980715221232', $result->debtor->personalId);
    }

    public function testGlobalConfidenceReflectsFieldsFound(): void
    {
        $document = $this->makeDocument('contract.pdf');

        $result = $this->strategy->extract($document);

        $this->assertGreaterThan(0.7, $result->globalConfidence);
        $this->assertLessThanOrEqual(1.0, $result->globalConfidence);
    }

    public function testInvalidCuiChecksumIsRejected(): void
    {
        $document = $this->makeDocument('contract-invalid-cui.pdf');

        $result = $this->strategy->extract($document);

        // The invalid CUI 12345678 (creditor side) must NOT be persisted anywhere.
        $this->assertNotSame('12345678', $result->creditor?->cui);
        $this->assertNotSame('12345678', $result->debtor?->cui);
        // The valid debtor CUI 14186770 should still be picked up.
        $this->assertNotNull($result->debtor);
        $this->assertSame('14186770', $result->debtor->cui);
    }

    public function testInvalidCnpChecksumIsRejected(): void
    {
        $document = $this->makeDocument('contract-invalid-cnp.pdf');

        $result = $this->strategy->extract($document);

        // CNP 1234567890123 has invalid checksum — must NOT be persisted.
        $this->assertNotSame('1234567890123', $result->debtor?->personalId);
        $this->assertNotSame('1234567890123', $result->creditor?->personalId);
    }

    // ---------- helpers ----------

    private function makeDocument(string $filename, string $mime = 'application/pdf'): Document
    {
        $document = new Document();
        $document->setStoredFilename($filename);
        $document->setMimeType($mime);

        return $document;
    }

    private static function generatePdf(string $filename, string $html): void
    {
        $dompdf = new Dompdf(['isPhpEnabled' => false, 'isRemoteEnabled' => false]);
        $dompdf->loadHtml(
            '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>',
        );
        $dompdf->setPaper('A4');
        $dompdf->render();

        file_put_contents(self::$fixturesDir . '/' . $filename, $dompdf->output());
    }
}
