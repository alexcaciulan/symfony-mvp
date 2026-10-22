<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * Numbers the annex pages continuously by stamping a number at the bottom right
 * of each page. The overlay is laid over the original content by qpdf, so the
 * pages themselves are not re-rendered. Landscape and rotated pages get a
 * landscape overlay page, which qpdf fits to the displayed page.
 */
final class PdfPageNumberOverlay
{
    public function __construct(
        private readonly ProcessSandbox $sandbox,
        private readonly QpdfRunner $qpdf,
        private readonly HtmlToPdfRenderer $renderer,
        private readonly PdfTextProbe $textProbe,
    ) {}

    public function apply(string $input, string $output, int $firstNumber): void
    {
        $pageCount = $this->qpdf->pageCount($input);
        $numbers = range($firstNumber, $firstNumber + $pageCount - 1);

        $portrait = $this->sandbox->tempPath();
        $landscape = $this->sandbox->tempPath();
        $this->renderer->render('pdf/page_number_overlay.html.twig', ['numbers' => $numbers], $portrait, 'portrait');

        $selection = [];
        $needsLandscape = false;
        foreach ($this->textProbe->pageSizes($input, $pageCount) as $i => $size) {
            $isLandscape = $size['width'] > $size['height'];
            $needsLandscape = $needsLandscape || $isLandscape;
            $selection[] = [$isLandscape ? $landscape : $portrait, (string) ($i + 1)];
        }
        if ($needsLandscape) {
            $this->renderer->render('pdf/page_number_overlay.html.twig', ['numbers' => $numbers], $landscape, 'landscape');
        }

        $overlay = $this->sandbox->tempPath();
        $this->qpdf->selectPages($selection, $overlay);
        $this->qpdf->overlay($input, $overlay, $output);
    }
}
