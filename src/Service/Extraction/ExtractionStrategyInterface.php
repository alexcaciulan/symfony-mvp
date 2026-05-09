<?php

namespace App\Service\Extraction;

use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;

/**
 * Contract for document-extraction strategies in the cascade pipeline.
 *
 * Strategies are tagged `app.extraction_strategy` (see config/services.yaml)
 * and consumed by {@see DataExtractionService} as an iterable, sorted by
 * `priority()` descending. The first strategy whose `supports()` returns
 * true and `extract()` produces a result with `globalConfidence >=` threshold
 * wins; the cascade then short-circuits.
 *
 * `isAiBacked()` controls GDPR-driven skip in `LOCAL_ONLY` extraction mode.
 */
interface ExtractionStrategyInterface
{
    /**
     * Whether this strategy can handle the given document (e.g. mime type,
     * presence of a text layer, language match). Cheap pre-check — no I/O
     * if avoidable. Strategies that cannot decide without doing the work
     * should return `true` and let `extract()` produce a low-confidence DTO.
     */
    public function supports(Document $document): bool;

    /**
     * Performs the extraction and returns a structured DTO. Must always
     * return a valid `ExtractedDocumentData` — signal "no data" through
     * `globalConfidence = 0.0` and null sub-DTOs, never via exceptions
     * for routine misses (reserve exceptions for infrastructure failures).
     */
    public function extract(Document $document): ExtractedDocumentData;

    /**
     * Cascade priority. Higher = tried first. Conventional values:
     *   100 = PdfParser (text-based PDFs, free, instant)
     *    70 = OcrText (Tesseract + Claude text)
     *    50 = AiVision (Claude vision)
     *    10 = Stub (Null Object fallback, always last)
     */
    public function priority(): int;

    /**
     * Whether this strategy sends document data to an external AI provider.
     * The orchestrator skips AI-backed strategies when the resolved
     * `ExtractionMode` is `LOCAL_ONLY` (GDPR data-minimisation).
     */
    public function isAiBacked(): bool;
}
