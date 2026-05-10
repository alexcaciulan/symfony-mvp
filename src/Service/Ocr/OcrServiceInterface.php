<?php

namespace App\Service\Ocr;

use App\DTO\Ocr\OcrResult;

/**
 * Contract for OCR providers that turn an image or PDF file path into
 * a structured {@see OcrResult}. Default implementation:
 * {@see TesseractOcrService}. A future {@see GoogleVisionOcrService} (or
 * other paid provider) could swap in via DI without touching callers.
 *
 * Implementations MUST throw {@see OcrException} on any failure (binary
 * missing, unsupported MIME, unreadable file, non-zero exit code) — never
 * return a partial / placeholder result silently.
 */
interface OcrServiceInterface
{
    /**
     * Runs OCR on the file at `$absolutePath` and returns the recognised text
     * with confidence and page-count metadata.
     *
     * @throws OcrException
     */
    public function extractText(string $absolutePath): OcrResult;
}
