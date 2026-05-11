<?php

namespace App\Service\Extraction;

use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrator for the document-extraction cascade.
 *
 * Combines four design patterns:
 *   - Strategy: each {@see ExtractionStrategyInterface} encapsulates one extraction approach.
 *   - Tagged Iterator (Symfony DI): strategies are auto-collected via the `app.extraction_strategy` tag.
 *   - Chain of Responsibility: strategies are tried in priority-descending order; the first one
 *     above the confidence threshold wins, the rest are skipped.
 *   - Template Method: the orchestration algorithm (resolve mode → filter → sort → loop → persist)
 *     is fixed here; strategies only customize the extraction step.
 *
 * The {@see StubExtractionStrategy} (Null Object) is always present at the end, so the cascade
 * never returns without a result. GDPR mode `LOCAL_ONLY` skips AI-backed strategies via
 * `ExtractionStrategyInterface::isAiBacked()`.
 *
 * Persistence: mutates the {@see Document} (extractedData / status / confidence / strategy) but
 * does NOT flush — the caller (sync controller, async handler at Pas 2.6, etc.) decides lifecycle.
 *
 * Not declared `final` so PHPUnit can mock it in the Pas 2.6 handler tests
 * (`tests/MessageHandler/ExtractDataMessageHandlerTest.php`). Extracting an
 * interface would be over-engineering for a service with one implementation;
 * relaxing `final` is the smaller change and still discourages subclassing
 * elsewhere via convention.
 */
class DataExtractionService
{
    public const DEFAULT_CONFIDENCE_THRESHOLD = 0.6;

    /** @var list<ExtractionStrategyInterface> */
    private array $sortedStrategies;

    /**
     * @param iterable<ExtractionStrategyInterface> $strategies tagged `app.extraction_strategy`
     */
    public function __construct(
        iterable $strategies,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $list = [];
        foreach ($strategies as $strategy) {
            $list[] = $strategy;
        }
        usort($list, static fn(ExtractionStrategyInterface $a, ExtractionStrategyInterface $b)
            => $b->priority() <=> $a->priority());
        $this->sortedStrategies = $list;
    }

    /**
     * Runs the cascade and persists the result on the Document.
     *
     * Algorithm: iterate strategies (priority desc) → skip AI under LOCAL_ONLY →
     * skip non-supporting → run extract → if globalConfidence ≥ threshold, accept
     * and short-circuit; otherwise continue, tracking the highest-confidence
     * partial result seen. If no strategy clears the threshold, persist the
     * best-below-threshold partial (e.g., a 0.4 PdfParser result is preferred
     * over the 0.0 Stub fallback). Document.extractionStatus reflects whether
     * any data was extracted: FAILED when globalConfidence is 0.0, COMPLETED
     * otherwise (including partial results).
     *
     * @param ?float $confidenceThreshold null = use {@see self::DEFAULT_CONFIDENCE_THRESHOLD}
     */
    public function extract(Document $document, ?float $confidenceThreshold = null): ExtractedDocumentData
    {
        $threshold = $confidenceThreshold ?? self::DEFAULT_CONFIDENCE_THRESHOLD;
        $mode = $this->resolveExtractionMode($document);
        $aiAllowed = $mode->isAiAllowed();

        $bestSoFar = null;
        foreach ($this->sortedStrategies as $strategy) {
            if (!$aiAllowed && $strategy->isAiBacked()) {
                $this->logger->debug('extraction.skip_ai_local_only', [
                    'documentId' => $document->getId(),
                    'strategy' => $strategy::class,
                ]);
                continue;
            }

            if (!$strategy->supports($document)) {
                continue;
            }

            // Defense-in-depth: each strategy declares LlmException / OcrException
            // as its `@throws` contract, but unexpected throwables (corrupt-PDF
            // fatals from smalot, OOM during base64 encoding, parse errors on
            // malformed JSON) would otherwise abort the entire cascade and
            // leave the Document stuck in PROCESSING. Catching `\Throwable`
            // here lets the next strategy try — critical for the upcoming
            // Pas 2.6 async messenger handler where one bad document must not
            // block the whole queue.
            try {
                $result = $strategy->extract($document);
            } catch (\Throwable $e) {
                $this->logger->error('extraction.strategy_unexpected_failure', [
                    'documentId' => $document->getId(),
                    'strategy' => $strategy::class,
                    'exceptionClass' => $e::class,
                    'code' => $e->getCode(),
                ]);
                continue;
            }

            if ($bestSoFar === null || $result->globalConfidence > $bestSoFar->globalConfidence) {
                $bestSoFar = $result;
            }

            if ($result->globalConfidence >= $threshold) {
                $this->logger->info('extraction.strategy_accepted', [
                    'documentId' => $document->getId(),
                    'strategy' => $result->strategy,
                    'confidence' => $result->globalConfidence,
                ]);
                $this->persistResult($document, $result);

                return $result;
            }

            $this->logger->debug('extraction.strategy_below_threshold', [
                'documentId' => $document->getId(),
                'strategy' => $result->strategy,
                'confidence' => $result->globalConfidence,
                'threshold' => $threshold,
            ]);
        }

        // Cascade exhausted without meeting threshold. In production the Stub
        // strategy (priority 10, supports() = true, isAiBacked = false) ensures
        // $bestSoFar is never null. Defensive fallback for unusual test/DI configs.
        if ($bestSoFar === null) {
            $bestSoFar = new ExtractedDocumentData(
                sourceDocumentId: (int) $document->getId(),
                strategy: StubExtractionStrategy::STRATEGY_KEY,
                globalConfidence: 0.0,
                extractedAt: new \DateTimeImmutable(),
            );
        }

        $this->persistResult($document, $bestSoFar);

        return $bestSoFar;
    }

    private function resolveExtractionMode(Document $document): ExtractionMode
    {
        // Pas 3.0: Document can be uploaded BEFORE a LegalCase exists (wizard step 0).
        // In that case there is no per-case override, so fall back to the
        // user's preference. Prefer LegalCase->getUser() when the case is
        // attached (post-wizard flow) and fall back to Document.uploadedBy
        // for Pas 3.0 wizard step 0 uploads where the case doesn't exist yet.
        $case = $document->getLegalCase();
        if ($case !== null) {
            $override = $case->getExtractionModeOverride();
            if ($override !== null) {
                return $override;
            }

            return $case->getUser()->getExtractionMode();
        }

        return $document->getUploadedBy()->getExtractionMode();
    }

    private function persistResult(Document $document, ExtractedDocumentData $result): void
    {
        // confidence == 0.0 = no data extracted (Stub fallback or all strategies failed) → FAILED.
        // Any positive confidence (including below threshold) = some data extracted → COMPLETED.
        // Downstream consumers (Pas 3.0 wizard pre-fill) must check both status and confidence
        // to decide whether to display extracted values or prompt manual entry.
        $status = $result->globalConfidence > 0.0
            ? ExtractionStatus::COMPLETED
            : ExtractionStatus::FAILED;

        $document->setExtractedData($result->toArray());
        $document->setExtractionStatus($status);
        $document->setExtractionConfidence(number_format($result->globalConfidence, 2, '.', ''));
        $document->setExtractionStrategy($result->strategy);
    }
}
