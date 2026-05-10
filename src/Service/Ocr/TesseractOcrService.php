<?php

namespace App\Service\Ocr;

use App\DTO\Ocr\OcrResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

/**
 * Tesseract-based OCR implementation.
 *
 * Pipeline:
 *   1. Sniff MIME via PHP `finfo`. Supported: `image/jpeg`, `image/png`,
 *      `image/jpg`, `application/pdf`. Anything else throws OcrException.
 *   2. For images: shell-exec `tesseract <input> - -l ron+eng -c tessedit_create_tsv=1`
 *      via {@see Process} (array form for command-injection safety). Parse the
 *      TSV output to compute the per-word confidence average.
 *   3. For PDFs: render each page to PNG via `pdftoppm -r 300 input.pdf prefix -png`
 *      into a unique temp directory, run tesseract on each PNG, concatenate the
 *      text and average the confidences. Cleanup is unconditional via try/finally.
 *
 * Errors surface as {@see OcrException}; the underlying process stderr is
 * preserved on the exception message and on a `logger->error` call so that
 * Pas 2.5.7 callers (and future ops debugging) can diagnose without losing
 * context. No PII is logged — only document path + binary stderr.
 *
 * This service does NOT mask the OCR text. Callers (Pas 2.5.7
 * `OcrTextExtractionStrategy`) MUST apply {@see App\Util\PiiMasker::maskCnp}
 * before sending the text to an external AI provider.
 */
final class TesseractOcrService implements OcrServiceInterface
{
    private const SUPPORTED_IMAGE_MIME = ['image/jpeg', 'image/jpg', 'image/png'];

    private const SUPPORTED_PDF_MIME = 'application/pdf';

    /** Seconds before tesseract / pdftoppm are killed. PDFs with ~10-20 pages stay well under this. */
    private const PROCESS_TIMEOUT = 60;

    /** Rasterisation DPI for `pdftoppm`. 300 is the practical sweet spot for OCR accuracy vs memory. */
    private const PDF_RENDER_DPI = 300;

    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
        private string $tesseractLanguages = 'ron+eng',
    ) {}

    public function extractText(string $absolutePath): OcrResult
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new OcrException(sprintf('OCR input not found or unreadable: %s', $absolutePath));
        }

        $mime = $this->detectMime($absolutePath);

        if (in_array($mime, self::SUPPORTED_IMAGE_MIME, true)) {
            return $this->extractFromImage($absolutePath);
        }

        if ($mime === self::SUPPORTED_PDF_MIME) {
            return $this->extractFromPdf($absolutePath);
        }

        throw new OcrException(sprintf('Unsupported MIME type for OCR: %s (path: %s)', $mime, $absolutePath));
    }

    // ---------- image path ----------

    private function extractFromImage(string $imagePath): OcrResult
    {
        [$text, $confidence] = $this->runTesseract($imagePath);

        return new OcrResult(
            text: $this->normaliseWhitespace($text),
            confidence: $confidence,
            pageCount: 1,
        );
    }

    // ---------- PDF path ----------

    private function extractFromPdf(string $pdfPath): OcrResult
    {
        $tempDir = sys_get_temp_dir() . '/tesseract-' . uniqid('', true);
        $createdFiles = [];

        try {
            if (!mkdir($tempDir, 0o700, true) && !is_dir($tempDir)) {
                throw new OcrException(sprintf('Could not create OCR temp directory: %s', $tempDir));
            }

            // pdftoppm produces files named `<prefix>-1.png`, `<prefix>-2.png`, ...
            $prefix = $tempDir . '/page';
            $process = new Process([
                'pdftoppm',
                '-r', (string) self::PDF_RENDER_DPI,
                '-png',
                $pdfPath,
                $prefix,
            ]);
            $process->setTimeout(self::PROCESS_TIMEOUT);
            try {
                // Catch RuntimeException (parent of ProcessTimedOutException +
                // ProcessStartFailedException) — covers the "binary missing" and
                // "exceeded timeout" failure modes in one branch. Exit-code !=0
                // is checked separately via isSuccessful() below.
                $process->run();
            } catch (\RuntimeException $e) {
                throw new OcrException(
                    sprintf('pdftoppm failed for %s: %s', $pdfPath, $e->getMessage()),
                    0,
                    $e,
                );
            }

            if (!$process->isSuccessful()) {
                throw new OcrException(sprintf(
                    'pdftoppm exited with status %d for %s. Stderr: %s',
                    $process->getExitCode() ?? -1,
                    $pdfPath,
                    trim($process->getErrorOutput()),
                ));
            }

            $pages = glob($prefix . '-*.png') ?: [];
            sort($pages, SORT_NATURAL);
            $createdFiles = $pages;

            if ($pages === []) {
                throw new OcrException(sprintf('pdftoppm produced no pages for %s', $pdfPath));
            }

            $combinedText = '';
            $confidences = [];
            foreach ($pages as $pagePath) {
                [$pageText, $pageConfidence] = $this->runTesseract($pagePath);
                $combinedText .= ($combinedText === '' ? '' : "\n") . $pageText;
                if ($pageConfidence > 0.0) {
                    $confidences[] = $pageConfidence;
                }
            }

            $globalConfidence = $confidences === [] ? 0.0 : array_sum($confidences) / count($confidences);

            return new OcrResult(
                text: $this->normaliseWhitespace($combinedText),
                confidence: $globalConfidence,
                pageCount: count($pages),
            );
        } finally {
            foreach ($createdFiles as $file) {
                @unlink($file);
            }
            @rmdir($tempDir);
        }
    }

    // ---------- tesseract invocation + TSV parsing ----------

    /**
     * Runs `tesseract <input> - -l <langs> -c tessedit_create_tsv=1` and returns
     * [text, confidence0to1]. Throws on any binary error.
     *
     * @return array{0: string, 1: float}
     */
    private function runTesseract(string $imagePath): array
    {
        $process = new Process([
            'tesseract',
            $imagePath,
            '-', // stdout
            '-l', $this->tesseractLanguages,
            '-c', 'tessedit_create_tsv=1',
        ]);
        $process->setTimeout(self::PROCESS_TIMEOUT);

        try {
            // Catch RuntimeException (parent of ProcessTimedOutException +
            // ProcessStartFailedException) — covers binary-missing and timeout
            // in one branch. Exit-code != 0 is checked separately below.
            $process->run();
        } catch (\RuntimeException $e) {
            $this->logger->error('ocr.tesseract.process_failed', [
                'imagePath' => $imagePath,
                'error' => $e->getMessage(),
            ]);
            throw new OcrException(sprintf('tesseract failed for %s: %s', $imagePath, $e->getMessage()), 0, $e);
        }

        if (!$process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            $this->logger->error('ocr.tesseract.non_zero_exit', [
                'imagePath' => $imagePath,
                'exitCode' => $process->getExitCode(),
                'stderr' => $stderr,
            ]);
            throw new OcrException(sprintf(
                'tesseract exited with status %d for %s. Stderr: %s',
                $process->getExitCode() ?? -1,
                $imagePath,
                $stderr,
            ));
        }

        $tsv = $process->getOutput();

        return $this->parseTesseractTsv($tsv);
    }

    /**
     * Tesseract's `tessedit_create_tsv=1` output has a header row plus one row
     * per recognised token with columns:
     *   level, page_num, block_num, par_num, line_num, word_num, left, top,
     *   width, height, conf, text.
     * We aggregate `text` from level=5 rows (words) and average their `conf`
     * values, ignoring -1 (which Tesseract emits for layout-only tokens).
     *
     * @return array{0: string, 1: float}
     */
    private function parseTesseractTsv(string $tsv): array
    {
        $lines = preg_split('/\r?\n/', $tsv) ?: [];
        $words = [];
        $confidences = [];

        foreach ($lines as $i => $line) {
            if ($i === 0 || $line === '') {
                continue; // skip header + trailing blank
            }
            $cols = explode("\t", $line);
            if (count($cols) < 12) {
                continue; // malformed row
            }
            $level = (int) $cols[0];
            if ($level !== 5) {
                continue; // not a word-level token
            }
            $confRaw = (float) $cols[10];
            $text = trim($cols[11]);
            if ($text === '') {
                continue;
            }

            $words[] = $text;
            if ($confRaw >= 0.0) {
                $confidences[] = $confRaw;
            }
        }

        $combinedText = implode(' ', $words);
        $confidence = $confidences === [] ? 0.0 : (array_sum($confidences) / count($confidences)) / 100.0;

        return [$combinedText, $confidence];
    }

    // ---------- helpers ----------

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if ($mime === false) {
            throw new OcrException(sprintf('Could not determine MIME type for %s', $path));
        }

        return $mime;
    }

    private function normaliseWhitespace(string $text): string
    {
        return trim((string) preg_replace('/[ \t]+/', ' ', $text));
    }
}
