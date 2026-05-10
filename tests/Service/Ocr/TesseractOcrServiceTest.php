<?php

namespace App\Tests\Service\Ocr;

use App\DTO\Ocr\OcrResult;
use App\Service\Ocr\OcrException;
use App\Service\Ocr\TesseractOcrService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Mixed Nivel 1 (unit logic) + Nivel 2 (integration with real Tesseract +
 * static fixture images committed under tests/fixtures/ocr/).
 *
 * The whole class is skipped when Tesseract isn't on PATH so developers can
 * still run the rest of the suite on a host without Docker; CI / Docker
 * environments have tesseract installed by the Pas 2.5.5 Dockerfile update
 * and exercise the full flow.
 *
 * Nivel 3 (cascade end-to-end with the orchestrator + all real strategies)
 * is intentionally deferred to Pas 2.5.7 — at this point in the timeline
 * `OcrTextExtractionStrategy` doesn't exist yet, so there's no orchestrator
 * caller to wire into. See memory entry project_lexrecovery_pas_2_5_5.md
 * for the explicit transfer.
 */
class TesseractOcrServiceTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../fixtures/ocr';

    private ?TesseractOcrService $service = null;

    /**
     * Lazy service init + per-test skip when tesseract is missing. Keeps the
     * sanity-guard test {@see self::testFixturesAreCommittedAndReadable()} runnable
     * on hosts without the binary so accidental fixture deletion still fails
     * loudly instead of being masked by a class-wide skip.
     */
    private function requireTesseractService(): TesseractOcrService
    {
        if (trim((string) shell_exec('which tesseract')) === '') {
            $this->markTestSkipped('Tesseract binary not available; run inside Docker container (pas 2.5.5 Dockerfile)');
        }

        return $this->service ??= new TesseractOcrService(new NullLogger(), 'ron+eng');
    }

    public function testExtractsTextFromCleanImageFixture(): void
    {
        $result = $this->requireTesseractService()->extractText(self::FIXTURES_DIR . '/clean-text.png');

        $this->assertInstanceOf(OcrResult::class, $result);
        // The fixture renders "CUI RO15193236" / "Total: 5000.00 RON" /
        // "Scadenta: 15.06.2026". OCR may merge or split tokens (e.g. drop the
        // space before RO), so we assert presence of the meaningful substrings,
        // not exact equality.
        $this->assertStringContainsString('15193236', $result->text);
        $this->assertStringContainsString('5000', $result->text);
        $this->assertStringContainsString('2026', $result->text);
    }

    public function testReturnsConfidenceBetweenZeroAndOne(): void
    {
        $result = $this->requireTesseractService()->extractText(self::FIXTURES_DIR . '/clean-text.png');

        $this->assertGreaterThan(0.0, $result->confidence);
        $this->assertLessThanOrEqual(1.0, $result->confidence);
    }

    public function testCleanImageHasHighConfidence(): void
    {
        // Anti-aliased text on a white background should comfortably clear 0.7;
        // dropping below that signals a regression in tesseract config or fixture quality.
        $result = $this->requireTesseractService()->extractText(self::FIXTURES_DIR . '/clean-text.png');

        $this->assertGreaterThan(0.7, $result->confidence);
    }

    public function testReturnsPageCountOneForSingleImage(): void
    {
        $result = $this->requireTesseractService()->extractText(self::FIXTURES_DIR . '/clean-text.png');

        $this->assertSame(1, $result->pageCount);
    }

    public function testExtractsTextFromScannedPdfFixture(): void
    {
        // scanned-document.pdf wraps clean-text.png as an image-only PDF — no
        // text layer. Exercises the pdftoppm → tesseract per-page pipeline.
        $result = $this->requireTesseractService()->extractText(self::FIXTURES_DIR . '/scanned-document.pdf');

        $this->assertInstanceOf(OcrResult::class, $result);
        $this->assertStringContainsString('15193236', $result->text);
        $this->assertGreaterThan(0.0, $result->confidence);
        $this->assertSame(1, $result->pageCount);
    }

    public function testThrowsOcrExceptionForNonexistentFile(): void
    {
        $this->expectException(OcrException::class);
        $this->expectExceptionMessageMatches('/not found or unreadable/');

        $this->requireTesseractService()->extractText('/var/no-such-path/missing.png');
    }

    public function testThrowsOcrExceptionForUnsupportedMime(): void
    {
        // Direct construction of the temp path (vs tempnam + concat) — tempnam
        // creates a real file at the base name and returns it, so concatenating
        // .txt would leak the unextended sibling on every run.
        $tempPath = sys_get_temp_dir() . '/ocrtest-' . uniqid('', true) . '.txt';
        file_put_contents($tempPath, 'just plain text, not an image');

        try {
            $this->expectException(OcrException::class);
            $this->expectExceptionMessageMatches('/Unsupported MIME type/');

            $this->requireTesseractService()->extractText($tempPath);
        } finally {
            @unlink($tempPath);
        }
    }

    public function testCleanupRemovesPdfTempDirectory(): void
    {
        $tmpRoot = sys_get_temp_dir();
        $beforeDirs = glob($tmpRoot . '/tesseract-*') ?: [];

        $this->requireTesseractService()->extractText(self::FIXTURES_DIR . '/scanned-document.pdf');

        $afterDirs = glob($tmpRoot . '/tesseract-*') ?: [];
        $this->assertSameSize(
            $beforeDirs,
            $afterDirs,
            sprintf(
                'PDF extraction must clean up its tesseract-* temp dir. Leak detected: %s',
                implode(', ', array_diff($afterDirs, $beforeDirs)),
            ),
        );
    }

    public function testRejectsInvalidLanguageCode(): void
    {
        // Hard proof that $tesseractLanguages reaches the `-l` argument:
        // tesseract exits non-zero when asked for a language it doesn't have,
        // and our code wraps that into OcrException. If the parameter were
        // silently ignored (e.g. hardcoded inside the service), this would
        // succeed instead.
        $service = new TesseractOcrService(new NullLogger(), 'nonexistent_lang_xyz');

        $this->expectException(OcrException::class);
        $service->extractText(self::FIXTURES_DIR . '/clean-text.png');
    }

    public function testFixturesAreCommittedAndReadable(): void
    {
        // Independent of Tesseract availability — runs even on hosts without
        // the binary, so an accidentally deleted/gitignored fixture surfaces
        // as a real failure instead of being masked by `markTestSkipped`.
        // (Override setUp's skip by building the assertions before any
        // service call.)
        $png = self::FIXTURES_DIR . '/clean-text.png';
        $pdf = self::FIXTURES_DIR . '/scanned-document.pdf';

        $this->assertFileExists($png);
        $this->assertGreaterThan(1_000, filesize($png), 'PNG fixture suspiciously small');
        $pngMagic = file_get_contents($png, length: 8);
        $this->assertSame("\x89PNG\r\n\x1a\n", $pngMagic, 'PNG magic bytes mismatch');

        $this->assertFileExists($pdf);
        $this->assertGreaterThan(1_000, filesize($pdf), 'PDF fixture suspiciously small');
        $pdfMagic = file_get_contents($pdf, length: 4);
        $this->assertSame('%PDF', $pdfMagic, 'PDF magic bytes mismatch');
    }
}
