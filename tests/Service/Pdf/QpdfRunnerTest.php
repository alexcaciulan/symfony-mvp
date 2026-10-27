<?php

declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\Service\Pdf\PdfTextProbe;
use App\Service\Pdf\ProcessSandbox;
use App\Service\Pdf\QpdfRunner;
use App\Tests\Support\TestPdfSigner;
use App\Tests\Support\TestPdfVariants;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real binaries; skipped where they are not installed.
 */
final class QpdfRunnerTest extends KernelTestCase
{
    private ProcessSandbox $sandbox;
    private QpdfRunner $qpdf;
    private PdfTextProbe $text;

    protected function setUp(): void
    {
        if (!ProcessSandbox::binariesAvailable(['qpdf', 'gs', 'pdftotext'])) {
            self::markTestSkipped('qpdf, gs and poppler are required.');
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

    public function testOnlyTheSignatureWidgetIsDroppedAndOtherAnnotationsStay(): void
    {
        $commented = $this->sandbox->tempPath();
        TestPdfSigner::addFreeText($this->fixture('unsigned.pdf'), $commented, 'Vazut avocat', [40, 60, 200, 80]);
        $signed = $this->sandbox->tempPath();
        TestPdfSigner::sign($commented, $signed, ['visibleRect' => [34, 700, 300, 740]]);
        self::assertStringContainsString('Digitally signed by Test Signer', $this->text->pageTexts($signed)[0]);

        $copy = $this->sandbox->tempPath();
        self::assertTrue($this->qpdf->withoutSignatureWidgets($signed, $copy));

        $page = $this->text->pageTexts($copy)[0];
        self::assertStringNotContainsString('Digitally signed', $page);
        self::assertStringContainsString('Vazut avocat', $page);
        self::assertStringContainsString('CERERE DE ORDONANTA DE PLATA', $page);
    }

    public function testAFileWithoutSignatureWidgetsHasNothingToDrop(): void
    {
        $commented = $this->sandbox->tempPath();
        TestPdfSigner::addFreeText($this->fixture('unsigned.pdf'), $commented, 'Vazut avocat', [40, 60, 200, 80]);

        self::assertFalse($this->qpdf->withoutSignatureWidgets($commented, $this->sandbox->tempPath()));
    }

    public function testSignatureWidgetsOnEveryPageAreDroppedInOnePass(): void
    {
        $once = $this->sandbox->tempPath();
        TestPdfSigner::sign($this->fixture('unsigned.pdf'), $once, ['visibleRect' => [34, 700, 300, 740]]);
        $twice = $this->sandbox->tempPath();
        TestPdfSigner::sign($once, $twice, ['page' => -1, 'visibleRect' => [34, 700, 300, 740]]);

        $copy = $this->sandbox->tempPath();
        self::assertTrue($this->qpdf->withoutSignatureWidgets($twice, $copy));

        foreach ($this->text->pageTexts($copy) as $page) {
            self::assertStringNotContainsString('Digitally signed', $page);
        }
    }

    public function testThePartTokenIsReadFromInfo(): void
    {
        $path = $this->sandbox->tempPath();
        $this->qpdf->setInfoKey($this->fixture('unsigned.pdf'), $path, QpdfRunner::PART_ID_KEY, 'info-token');

        self::assertSame(['info' => 'info-token', 'xmp' => null], $this->qpdf->readPartToken($path));
    }

    public function testThePartTokenIsReadFromXmpWhenInfoIsGone(): void
    {
        foreach ([false, true] as $asAttribute) {
            $tokened = $this->sandbox->tempPath();
            $this->qpdf->setInfoKey($this->fixture('unsigned.pdf'), $tokened, QpdfRunner::PART_ID_KEY, 'info-token');
            $bare = $this->sandbox->tempPath();
            TestPdfVariants::removeInfo($tokened, $bare);
            $path = $this->sandbox->tempPath();
            TestPdfVariants::withXmpToken($bare, $path, 'xmp&token', $asAttribute);

            self::assertSame(['info' => null, 'xmp' => 'xmp&token'], $this->qpdf->readPartToken($path), $asAttribute ? 'attribute' : 'element');
        }
    }

    public function testARedistilledFileHasNoPartToken(): void
    {
        $tokened = $this->sandbox->tempPath();
        $this->qpdf->setInfoKey($this->fixture('unsigned.pdf'), $tokened, QpdfRunner::PART_ID_KEY, 'info-token');
        $redistilled = $this->sandbox->tempPath();
        TestPdfVariants::gsPdfwrite($tokened, $redistilled);

        self::assertSame(['info' => null, 'xmp' => null], $this->qpdf->readPartToken($redistilled));
    }

    public function testTheXmpTokenNeedsTheExactProperty(): void
    {
        self::assertNull(QpdfRunner::xmpPartToken('<pdfx:OtherId>abc</pdfx:OtherId>'));
        self::assertNull(QpdfRunner::xmpPartToken('<pdfx:LexRecoveryPartId>  </pdfx:LexRecoveryPartId>'));
        self::assertSame('a"b', QpdfRunner::xmpPartToken('<x pdfx:LexRecoveryPartId=\'a&quot;b\'/>'));
    }

    private function fixture(string $name): string
    {
        return TestPdfSigner::FIXTURE_DIR . '/' . $name;
    }
}
