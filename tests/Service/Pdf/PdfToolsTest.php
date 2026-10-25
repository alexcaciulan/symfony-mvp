<?php

declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\Service\Pdf\GhostscriptCompressor;
use App\Service\Pdf\HtmlToPdfRenderer;
use App\Service\Pdf\ImageToPdfConverter;
use App\Service\Pdf\PdfPageNumberOverlay;
use App\Service\Pdf\PdfTextProbe;
use App\Service\Pdf\PdfToolException;
use App\Service\Pdf\ProcessSandbox;
use App\Service\Pdf\QpdfRunner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Runs the real binaries; skipped where they are not installed.
 */
final class PdfToolsTest extends KernelTestCase
{
    private ProcessSandbox $sandbox;
    private QpdfRunner $qpdf;
    private PdfTextProbe $text;

    protected function setUp(): void
    {
        if (!ProcessSandbox::binariesAvailable(['qpdf', 'gs', 'magick', 'pdftotext', 'pdfinfo'])) {
            self::markTestSkipped('qpdf, gs, magick and poppler are required.');
        }

        self::bootKernel();
        $this->sandbox = static::getContainer()->get(ProcessSandbox::class);
        $this->sandbox->begin();
        $this->qpdf = static::getContainer()->get(QpdfRunner::class);
        $this->text = static::getContainer()->get(PdfTextProbe::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->sandbox)) {
            $this->sandbox->end();
        }
        parent::tearDown();
    }

    public function testMergeAddsUpThePages(): void
    {
        $a = $this->textPdf(['Alpha one', 'Alpha two']);
        $b = $this->textPdf(['Beta one']);
        $out = $this->sandbox->tempPath();

        $this->qpdf->merge([$a, $b], $out);

        self::assertSame(3, $this->qpdf->pageCount($out));
        self::assertSame(['Alpha one', 'Alpha two', 'Beta one'], $this->text->pageTexts($out));
    }

    public function testInspectRefusesAnEncryptedPdf(): void
    {
        $plain = $this->textPdf(['Secret']);
        $encrypted = $this->sandbox->tempPath();
        $this->sandbox->run(['qpdf', '--encrypt', 'user-pass', 'owner-pass', '256', '--', $plain, $encrypted]);

        try {
            $this->qpdf->inspect($encrypted);
            self::fail('An encrypted PDF must be refused.');
        } catch (PdfToolException $e) {
            self::assertSame('filing.package.error.encrypted', $e->messageKey);
        }
    }

    public function testInspectAcceptsAPlainPdfAndCountsItsPages(): void
    {
        self::assertSame(2, $this->qpdf->inspect($this->textPdf(['One', 'Two']))->pageCount);
    }

    public function testInfoKeyIsWrittenAndReadBack(): void
    {
        $in = $this->textPdf(['Token page']);
        $out = $this->sandbox->tempPath();

        $this->qpdf->setInfoKey($in, $out, QpdfRunner::PART_ID_KEY, 'abc123def456');

        self::assertSame('abc123def456', $this->qpdf->readInfoKey($out, QpdfRunner::PART_ID_KEY));
        self::assertSame(['Token page'], $this->text->pageTexts($out));
    }

    public function testInfoKeyIsAddedToAFileWithoutInfoDictionary(): void
    {
        $merged = $this->sandbox->tempPath();
        $this->qpdf->merge([$this->textPdf(['No info'])], $merged);
        $out = $this->sandbox->tempPath();

        $this->qpdf->setInfoKey($merged, $out, QpdfRunner::PART_ID_KEY, 'fresh-token');

        self::assertSame('fresh-token', $this->qpdf->readInfoKey($out, QpdfRunner::PART_ID_KEY));
    }

    public function testCompressorKeepsASmallerOutputWithTheSamePagesAndText(): void
    {
        $in = $this->noisyImagePdf('Invoice 2024/17 total 1500 RON');

        $result = static::getContainer()->get(GhostscriptCompressor::class)->compress($in);

        self::assertTrue($result->applied, 'A high-resolution scan must compress.');
        self::assertLessThan($result->bytesBefore, $result->bytesAfter);
        self::assertSame(1, $this->qpdf->pageCount($result->path));
        self::assertSame($this->text->pageTexts($in), $this->text->pageTexts($result->path));
    }

    public function testCompressorDiscardsAnOutputThatIsNotSmaller(): void
    {
        // A tiny text-only PDF: Ghostscript's rewrite carries more overhead than it saves.
        $in = $this->textPdf(['x']);
        $sizeBefore = filesize($in);

        $result = static::getContainer()->get(GhostscriptCompressor::class)->compress($in);

        self::assertFalse($result->applied);
        self::assertSame($in, $result->path);
        self::assertSame($sizeBefore, $result->bytesAfter);
    }

    public function testImageBecomesOneA4Page(): void
    {
        $png = $this->sandbox->tempPath('png');
        $this->sandbox->run(['magick', '-size', '800x600', 'xc:white', '-fill', 'black', '-draw', 'rectangle 100,100 700,500', $png]);
        $out = $this->sandbox->tempPath();

        static::getContainer()->get(ImageToPdfConverter::class)->convert($png, $out);

        self::assertSame(1, $this->qpdf->inspect($out)->pageCount);
        $size = $this->text->pageSizes($out, 1)[0];
        self::assertEqualsWithDelta(595.0, $size['width'], 2.0);
        self::assertEqualsWithDelta(842.0, $size['height'], 2.0);
    }

    public function testOverlayNumbersPagesFromTheGivenNumberIncludingARotatedLandscapePage(): void
    {
        $base = $this->textPdf(['First body', 'Second body']);
        $rotated = $this->sandbox->tempPath();
        $this->sandbox->run(['qpdf', $base, '--rotate=+90:2', '--', $rotated], 60.0, [0, 3]);
        $out = $this->sandbox->tempPath();

        static::getContainer()->get(PdfPageNumberOverlay::class)->apply($rotated, $out, 7);

        $pages = $this->text->pageTexts($out);
        self::assertCount(2, $pages);
        self::assertStringContainsString('First body', $pages[0]);
        self::assertMatchesRegularExpression('/(^|\s)7(\s|$)/', $pages[0]);
        self::assertStringContainsString('Second body', $pages[1]);
        self::assertMatchesRegularExpression('/(^|\s)8(\s|$)/', $pages[1]);
    }

    /**
     * @param list<string> $pages
     */
    private function textPdf(array $pages): string
    {
        $html = '<html><body style="font-family: DejaVu Sans">';
        foreach ($pages as $i => $page) {
            $html .= ($i > 0 ? '<div style="page-break-before: always"></div>' : '') . '<p>' . htmlspecialchars($page) . '</p>';
        }
        $html .= '</body></html>';

        $out = $this->sandbox->tempPath();
        (new HtmlToPdfRenderer(new Environment(new ArrayLoader(['t' => $html]))))->render('t', [], $out);

        return $out;
    }

    /** A page with a text layer over a large noisy image, the shape of a scanned annex. */
    private function noisyImagePdf(string $caption): string
    {
        $png = $this->sandbox->tempPath('png');
        $this->sandbox->run(['magick', '-size', '2400x3200', 'xc:', '+noise', 'Random', '-colorspace', 'Gray', $png]);
        $jpeg = $this->sandbox->tempPath('jpg');
        $this->sandbox->run(['magick', $png, '-quality', '98', $jpeg]);

        $html = '<html><body style="font-family: DejaVu Sans"><p>' . htmlspecialchars($caption) . '</p>'
            . '<img src="data:image/jpeg;base64,' . base64_encode((string) file_get_contents($jpeg)) . '" style="width:170mm;height:226mm"></body></html>';
        $out = $this->sandbox->tempPath();
        (new HtmlToPdfRenderer(new Environment(new ArrayLoader(['t' => $html]))))->render('t', [], $out);

        return $out;
    }
}
