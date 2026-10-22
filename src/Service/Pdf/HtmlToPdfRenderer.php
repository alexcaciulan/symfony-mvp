<?php

declare(strict_types=1);

namespace App\Service\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Renders a Twig template to a PDF file at a given path. Separate from
 * AbstractPdfGenerator on purpose: that one writes Document rows and overwrites
 * the case's stored files, and nothing built for a filing package may do either.
 */
final class HtmlToPdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    /**
     * @param array<string, mixed> $context
     *
     * @return int number of pages rendered
     */
    public function render(string $template, array $context, string $outputPath, string $orientation = 'portrait'): int
    {
        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render($template, $context));
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();

        if (file_put_contents($outputPath, $dompdf->output()) === false) {
            throw new PdfToolException(sprintf('Cannot write %s', $outputPath));
        }

        return $dompdf->getCanvas()->get_page_count();
    }
}
