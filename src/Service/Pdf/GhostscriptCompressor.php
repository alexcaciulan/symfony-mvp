<?php

declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * Lossy compression of an unsigned annex, run locally. Never applied to a signed
 * file, the petition or the power of attorney. The output is kept only when it is
 * smaller, has the same number of pages and the same text on every page, so a
 * scan the compression would garble keeps its original bytes.
 */
final class GhostscriptCompressor
{
    public function __construct(
        private readonly ProcessSandbox $sandbox,
        private readonly QpdfRunner $qpdf,
        private readonly PdfTextProbe $textProbe,
    ) {}

    public function compress(string $input): CompressionResult
    {
        $before = (int) filesize($input);
        $output = $this->sandbox->tempPath();

        try {
            $this->sandbox->run([
                'gs', '-dSAFER', '-dNOPAUSE', '-dBATCH', '-dQUIET',
                '-sDEVICE=pdfwrite',
                '-dPDFSETTINGS=/ebook',
                '-dColorImageResolution=150',
                '-dGrayImageResolution=150',
                '-dMonoImageResolution=300',
                '-dDetectDuplicateImages=true',
                '-sOutputFile=' . $output,
                $input,
            ], 90.0);
        } catch (PdfToolException $e) {
            if ($e->messageKey !== 'filing.package.error.tool_failed') {
                throw $e;
            }

            // A file Ghostscript cannot rewrite is still a valid annex as it is.
            return new CompressionResult(false, $input, $before, $before);
        }

        $after = is_file($output) ? (int) filesize($output) : 0;
        if ($after === 0 || $after >= $before) {
            return new CompressionResult(false, $input, $before, $before);
        }

        if ($this->qpdf->pageCount($output) !== $this->qpdf->pageCount($input)) {
            return new CompressionResult(false, $input, $before, $before);
        }

        // Pages with no text layer (pure scans) can only be checked by count.
        $originalTexts = $this->textProbe->pageTexts($input);
        $compressedTexts = $this->textProbe->pageTexts($output);
        foreach ($originalTexts as $i => $text) {
            if ($text !== '' && ($compressedTexts[$i] ?? null) !== $text) {
                return new CompressionResult(false, $input, $before, $before);
            }
        }

        return new CompressionResult(true, $output, $before, $after);
    }
}
