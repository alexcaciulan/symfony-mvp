<?php

namespace App\Service\Extraction;

use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\ExtractionFailureReason;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionPipeline;
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
     * more at lower confidence).
     *
     * It means two different things per pipeline. On LEGACY_CASCADE it is the
     * short-circuit point: PdfParser on realistic Romanian invoices/contracts
     * yields ~0.25–0.45 coverage (it can't see emails, phones, administrators,
     * ONRC numbers reliably), falls below, and the AI tier gets a chance at the
     * missing fields; AI typically returns 0.55–0.85 and stops the chain. On
     * AI_ONLY there is no next tier to hand off to, so it only marks the result
     * as needing the lawyer's review, which is what the name says.
     *
     * Precedence: at runtime, the value injected via `services.yaml`
     * (`$confidenceThreshold`, bound from `EXTRACTION_REVIEW_THRESHOLD`)
     * takes priority. This PHP constant is the code-level fallback for tests
     * or for direct calls that pass `null` as the threshold argument. Keep the
     * two values in sync — the `.env` default and this constant should always
     * match so a fresh checkout behaves the same with or without env loading.
     */
    public const DEFAULT_REVIEW_THRESHOLD = 0.6;

    /**
     * Written to `Document.extractionStrategy` when the cascade stopped before
     * any strategy ran (policy skip). Keeps provenance honest.
     */
    public const NO_STRATEGY = 'none';

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
     * @param ?float $confidenceThreshold null = use {@see self::DEFAULT_REVIEW_THRESHOLD}
     */
    public function extract(Document $document, ?float $confidenceThreshold = null): ExtractedDocumentData
    {
        $threshold = $confidenceThreshold ?? self::DEFAULT_REVIEW_THRESHOLD;
        $mode = $this->resolveExtractionMode($document);
        $pipeline = $this->resolvePipeline($document);

        // Two independent conditions must both hold before a document may reach
        // an AI provider: the privacy mode must allow it, and the account holder
        // must have accepted sub-processing. The agreement is the one that
        // carries legal weight (professional secrecy, art. 28 GDPR), so it gates
        // every pipeline, not just AI_ONLY.
        $agreementGiven = $this->resolveOwner($document)->hasAcceptedAiProcessing();
        $aiAllowed = $mode->isAiAllowed() && $agreementGiven;

        // AI-only with no permitted strategy leaves nothing to run. That is a
        // settings choice, not a malfunction, so it gets its own status: FAILED
        // would send the lawyer looking for a broken document.
        if ($pipeline === ExtractionPipeline::AI_ONLY && !$aiAllowed) {
            // Name the deliberate choice first: an account parked on LOCAL_ONLY
            // asked for this, while a missing agreement means it was never asked.
            $reason = $mode->isAiAllowed()
                ? ExtractionFailureReason::AGREEMENT_MISSING
                : ExtractionFailureReason::LOCAL_ONLY_MODE;
            $this->logger->info('extraction.skipped_by_policy', [
                'documentId' => $document->getId(),
                'mode' => $mode->value,
                'reason' => $reason->value,
            ]);
            $result = new ExtractedDocumentData(
                sourceDocumentId: (int) $document->getId(),
                // No strategy ran, and extractionStrategy is provenance
                // evidence: naming one here would claim work that never happened.
                strategy: self::NO_STRATEGY,
                globalConfidence: 0.0,
                extractedAt: new \DateTimeImmutable(),
                failureReason: $reason,
            );
            $this->persistResult($document, $result, ExtractionStatus::SKIPPED_BY_POLICY);

            return $result;
        }

        $bestSoFar = null;
        foreach ($this->sortedStrategies as $strategy) {
            if (!$aiAllowed && $strategy->isAiBacked()) {
                $this->logger->debug('extraction.skip_ai_not_permitted', [
                    'documentId' => $document->getId(),
                    'strategy' => $strategy::class,
                    'agreementGiven' => $agreementGiven,
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

            // AI-only accounts run vision and nothing else: no PdfParser, no
            // OcrText, and no Stub safety net below them.
            if ($pipeline === ExtractionPipeline::AI_ONLY
                && $strategyKey !== AiVisionExtractionStrategy::STRATEGY_KEY) {
                $this->logger->debug('extraction.skip_non_ai_pipeline', [
                    'documentId' => $document->getId(),
                    'strategy' => $strategyKey ?? $strategy::class,
                ]);
                continue;
            }

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
                    // The class alone does not say what broke, and this arm
                    // catches programming errors as readily as provider ones:
                    // without the message an operator sees a document stuck on
                    // a transient reason and nothing to act on.
                    'message' => $e->getMessage(),
                    'origin' => $e->getFile() . ':' . $e->getLine(),
                ]);
                continue;
            }

            if ($this->isBetter($result, $bestSoFar)) {
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

        // Cascade exhausted without meeting the threshold. On LEGACY_CASCADE the
        // Stub strategy (priority 10, supports() = true, isAiBacked = false)
        // keeps $bestSoFar non-null. On AI_ONLY there is no Stub, so a null here
        // is the normal shape of "vision did not run at all" (missing API key,
        // unsupported mime) and this fallback is the real path, not a defensive
        // one. Every uploadable mime is one vision accepts, so under AI_ONLY the
        // remaining cause is the missing-API-key gate in supports().
        if ($bestSoFar === null) {
            $bestSoFar = new ExtractedDocumentData(
                sourceDocumentId: (int) $document->getId(),
                strategy: $pipeline === ExtractionPipeline::AI_ONLY
                    ? AiVisionExtractionStrategy::STRATEGY_KEY
                    : StubExtractionStrategy::STRATEGY_KEY,
                globalConfidence: 0.0,
                extractedAt: new \DateTimeImmutable(),
                // A missing API key and a provider outage look identical from
                // here, yet only one of them is worth retrying. Ask the vision
                // strategy which one it is.
                failureReason: $pipeline === ExtractionPipeline::AI_ONLY
                    ? ($this->visionConfigured()
                        ? ExtractionFailureReason::API_UNAVAILABLE
                        : ExtractionFailureReason::API_KEY_MISSING)
                    : null,
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

    /**
     * The case owner when the document belongs to a case, the uploader
     * otherwise. There is no per-case pipeline override: unlike the extraction
     * mode, the pipeline is a property of the account. The uploader leg is not a
     * fallback for odd data, it is the normal path for wizard step 0, where
     * documents are uploaded before any LegalCase exists.
     */
    private function resolvePipeline(Document $document): ExtractionPipeline
    {
        return $this->resolveOwner($document)->getExtractionPipeline();
    }

    /**
     * The account whose settings and agreement govern this document.
     */
    private function resolveOwner(Document $document): User
    {
        return $document->getLegalCase()?->getUser() ?? $document->getUploadedBy();
    }

    /**
     * Whether the AI Vision strategy has credentials. Without them it declines
     * every document in `supports()`, which is a configuration problem rather
     * than a transient outage and must not be retried.
     */
    private function visionConfigured(): bool
    {
        foreach ($this->sortedStrategies as $strategy) {
            if ($strategy instanceof AiVisionExtractionStrategy) {
                return $strategy->isConfigured();
            }
        }

        // No vision strategy registered at all (unit tests with hand-built
        // cascades). Nothing here can be fixed by an operator key, so treat it
        // as the outage case and let the existing retry policy decide.
        return true;
    }

    /**
     * Whether a fresh result should replace the best one seen so far.
     *
     * Confidence decides first. On a tie the question is what the two results
     * carry, and that splits in two.
     *
     * Where both carry data, the clean one wins: keeping a stale reason next to
     * a usable extraction would have the message handler queue a retry for a
     * cascade that already succeeded.
     *
     * Where neither carries data, nothing is being chosen between except the
     * explanation, and the result that has one is worth strictly more. The Stub
     * fallback ends every legacy cascade with an empty, reasonless result, so
     * the opposite rule silently erased the diagnosis the vision strategy had
     * just recorded. A malformed AI answer would then reach the lawyer as a
     * bare failure: the handler never sees a transient reason, so no retry is
     * queued, and the UI hides its retry button because nothing says the
     * failure was retryable. That is a dead end for a document that only needed
     * asking again.
     */
    private function isBetter(ExtractedDocumentData $candidate, ?ExtractedDocumentData $current): bool
    {
        if ($current === null) {
            return true;
        }
        if ($candidate->globalConfidence !== $current->globalConfidence) {
            return $candidate->globalConfidence > $current->globalConfidence;
        }

        if ($candidate->globalConfidence === 0.0) {
            // First diagnosis wins, and the cascade runs in priority order, so
            // the surviving reason is the most capable strategy's.
            return $candidate->failureReason !== null && $current->failureReason === null;
        }

        return $candidate->failureReason === null && $current->failureReason !== null;
    }

    /**
     * @param ?ExtractionStatus $forcedStatus set only when the caller already
     *        knows the outcome is not a plain success/failure (policy skip)
     */
    private function persistResult(
        Document $document,
        ExtractedDocumentData $result,
        ?ExtractionStatus $forcedStatus = null,
    ): void {
        // confidence == 0.0 = no data extracted (Stub fallback or all strategies failed) → FAILED.
        // Any positive confidence (including below threshold) = some data extracted → COMPLETED.
        // Downstream consumers (Pas 3.0 wizard pre-fill) must check both status and confidence
        // to decide whether to display extracted values or prompt manual entry.
        $status = $forcedStatus ?? ($result->globalConfidence > 0.0
            ? ExtractionStatus::COMPLETED
            : ExtractionStatus::FAILED);

        // What the model believes the document is, kept next to the type the
        // lawyer picked instead of replacing it. Only written when a classifying
        // prompt actually ran: a specialised prompt returns no classification,
        // and clearing the column there would erase the earlier detection on
        // every reprocess.
        $classification = $result->classification;
        if ($classification !== null) {
            $document->setDetectedType($classification->type);
            $document->setDetectedTypeConfidence(number_format($classification->confidence, 2, '.', ''));
        }

        $document->setExtractedData($result->toArray());
        $document->setExtractionStatus($status);
        $document->setExtractionConfidence(number_format($result->globalConfidence, 2, '.', ''));
        $document->setExtractionStrategy($result->strategy);
        $document->setExtractionFailureReason($result->failureReason);
    }
}
