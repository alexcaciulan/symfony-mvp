<?php

namespace App\DTO\Ocr;

/**
 * Result of an OCR pass over an image or PDF document.
 *
 *   - `text`        — concatenated text recognised across all pages, with stray
 *                     whitespace normalised. Empty string when nothing was read.
 *   - `confidence`  — average per-word confidence reported by Tesseract,
 *                     normalised to 0..1 (Tesseract reports 0..100 raw; words
 *                     with confidence -1 are excluded from the average).
 *   - `pageCount`   — number of pages processed (1 for a single image, N for
 *                     multipage PDFs after `pdftoppm` rasterisation).
 */
final readonly class OcrResult
{
    public function __construct(
        public string $text,
        public float $confidence,
        public int $pageCount,
    ) {}
}
