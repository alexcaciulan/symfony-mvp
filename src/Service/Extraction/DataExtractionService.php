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
    /**
     * Coverage-based threshold — see {@see CoverageConfidenceCalculator}.
     * 0.6 ≈ 15 of 25 wizard fields extracted at confidence 1.0 (or proportionally
     * more at lower confidence). Empirically PdfParser on realistic Romanian
     * invoices/contracts yields ~0.25–0.45 coverage (it can't see emails,
     * phones, administrators, ONRC numbers reliably), so it falls below and the
     * AI tier gets a chance to fill the missing 14+ fields. AI typically
     * returns 0.55–0.85 coverage and short-circuits. If both AI tiers fail
     * (LOCAL_ONLY, missing API key, rate limit), the best-below-threshold
     * fallback still persists the PdfParser partial — nothing is lost.
     *
     * Tune via `EXTRACTION_CONFIDENCE_THRESHOLD`: lower for cheaper extraction
     * (PdfParser-only when it covers enough); higher to favour AI even when
     * PdfParser did decently — lawyer time > AI cost is the operational bet.
     *
     * Precedence: at runtime, the value injected via `services.yaml`
     * (`$confidenceThreshold`, bound from `EXTRACTION_CONFIDENCE_THRESHOLD`)
     * takes priority. This PHP constant is the code-level fallback for tests
     * or for direct calls that pass `null` as the threshold argument. Keep the
     * two values in sync — the `.env` default and this constant should always
     * match so a fresh checkout behaves the same with or without env loading.
     */
    public const DEFAULT_CONFIDENCE_THRESHOLD = 0.6;

    /** @var list<ExtractionStrategyInterface> */
    private array $sortedStrategies;

    /** @var list<string> */
    private array $skippedStrategyKeys;

    /**
     * @param iterable<ExtractionStrategyInterface> $strategies tagged `app.extraction_strategy`
     * @param ?string $forceStrategyKey when set (via env EXTRACTION_FORCE_STRATEGY),
     *                                  cascade skips strategies whose ::STRATEGY_KEY
     *                                  doesn't match. Dev-only knob for testing a
     *                                  specific tier of the cascade in isolation;
     *                                  bypasses the priority-based short-circuit
     *                                  logic. Leave null in production. Values:
     *                                  `pdf_parser` | `ocr_text` | `ai_vision` | `stub`.
     * @param string $skipStrategyKeysCsv comma-separated list of STRATEGY_KEY values
     *                                    to skip (via env EXTRACTION_SKIP_STRATEGIES).
     *                                    Complementary to forceStrategyKey: cascade
     *                                    runs normally but jumps over the listed
     *                                    tiers. Useful for benchmarking the impact
     *                                    of one tier (e.g. `ocr_text` to estimate
     *                                    PdfParser→AiVision baseline) or for
     *                                    bypassing a tier known-broken pending fix.
     *                                    Empty string = no skip.
     */
    public function __construct(
        iterable $strategies,
        private LoggerInterface $logger = new NullLogger(),
        private ?string $forceStrategyKey = null,
        ?string $skipStrategyKeysCsv = null,
    ) {
        $list = [];
        foreach ($strategies as $strategy) {
            $list[] = $strategy;
        }
        usort($list, static fn(ExtractionStrategyInterface $a, ExtractionStrategyInterface $b)
            => $b->priority() <=> $a->priority());
        $this->sortedStrategies = $list;

        // `%env(default::...)%` resolves to null when the env var is absent
        // (test environments don't always source `.env`); treat null and ''
        // identically as "no override".
        $this->skippedStrategyKeys = ($skipStrategyKeysCsv === null || $skipStrategyKeysCsv === '')
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $skipStrategyKeysCsv))));
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

            // Strategy identification — production strategies declare a
            // `STRATEGY_KEY` constant; anonymous test doubles often don't.
            // Resolve defensively so the dev knobs below don't crash unit
            // tests that pass throw-away anonymous strategies through the
            // cascade.
            $strategyKey = defined($strategy::class . '::STRATEGY_KEY')
                ? $strategy::STRATEGY_KEY
                : null;

            // Dev-only knob: when EXTRACTION_FORCE_STRATEGY is set, skip every
            // strategy whose STRATEGY_KEY doesn't match. Lets us exercise a
            // specific cascade tier (e.g. AiVision) without contriving a
            // document that would naturally bypass the higher-priority tiers.
            // NEVER set this in production — it disables the priority-based
            // short-circuit and degrades extraction quality.
            $isForceMatched = $this->forceStrategyKey !== null
                && $this->forceStrategyKey !== ''
                && $strategyKey === $this->forceStrategyKey;
            if ($this->forceStrategyKey !== null && $this->forceStrategyKey !== ''
                && !$isForceMatched) {
                continue;
            }

            // Dev-only knob: when EXTRACTION_SKIP_STRATEGIES lists this tier,
            // jump over it but keep the cascade running on the rest. Inverse
            // of forceStrategyKey: leave it broad, mute one or two tiers.
            // Mutually compatible with forceStrategyKey (skip wins — a forced
            // strategy that's also in the skip list won't run).
            if ($strategyKey !== null
                && $this->skippedStrategyKeys !== []
                && in_array($strategyKey, $this->skippedStrategyKeys, true)) {
                $this->logger->debug('extraction.skip_strategy_dev_override', [
                    'documentId' => $document->getId(),
                    'strategy' => $strategyKey,
                ]);
                continue;
            }

            // When force-matched, bypass `supports()` — its main role is a
            // cascade-ordering optimization (e.g. OcrText defers to PdfParser
            // on PDFs that have a text layer). The force knob explicitly
            // overrides ordering, so the optimization is exactly what we want
            // to ignore. Any genuine incompatibility (wrong mime, missing
            // OCR binary, …) still surfaces via the strategy's own throws,
            // caught by the defensive `\Throwable` handler below.
            if (!$isForceMatched && !$strategy->supports($document)) {
                continue;
            }
            if ($isForceMatched && !$strategy->supports($document)) {
                $this->logger->info('extraction.force_bypass_supports', [
                    'documentId' => $document->getId(),
                    'strategy' => $strategyKey,
                ]);
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
