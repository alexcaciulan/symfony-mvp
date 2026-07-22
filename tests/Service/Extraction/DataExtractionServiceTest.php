<?php

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ExtractionFailureReason;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionPipeline;
use App\Enum\ExtractionStatus;
use App\Service\AuditLogService;
use App\Service\Extraction\AiVisionExtractionStrategy;
use App\Service\Extraction\DataExtractionService;
use App\Service\Extraction\ExtractionStrategyInterface;
use App\Service\Extraction\StubExtractionStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use App\Service\Llm\LlmClientInterface;
use App\Tests\Support\ExtractionPrompts;
use App\Enum\DocumentType;

class DataExtractionServiceTest extends TestCase
{
    public function testFallsBackToStubWhenNoStrategySupports(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 80, supports: false, strategyKey: 'fake_no'),
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument();
        $result = $service->extract($document);

        $this->assertSame('stub', $result->strategy);
        $this->assertSame(0.0, $result->globalConfidence);
    }

    public function testReturnsFirstStrategyAboveThreshold(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.95, strategyKey: 'fake_high'),
            new StubExtractionStrategy(),
        ]);

        $result = $service->extract($this->makeDocument());

        $this->assertSame('fake_high', $result->strategy);
        $this->assertSame(0.95, $result->globalConfidence);
    }

    public function testSkipsLowConfidenceAndContinuesCascade(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.3, strategyKey: 'fake_low'),
            $this->makeFake(priority: 70, confidence: 0.85, strategyKey: 'fake_mid'),
            new StubExtractionStrategy(),
        ]);

        $result = $service->extract($this->makeDocument());

        $this->assertSame('fake_mid', $result->strategy);
        $this->assertSame(0.85, $result->globalConfidence);
    }

    public function testAiOnlyPipelineSkipsEveryStrategyExceptAiVision(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.95, strategyKey: 'pdf_parser'),
            $this->makeFake(priority: 70, confidence: 0.9, strategyKey: 'ocr_text', isAi: true),
            $this->makeAiVisionDouble(confidence: 0.8),
            new StubExtractionStrategy(),
        ]);

        $result = $service->extract($this->makeDocument(
            userMode: ExtractionMode::BALANCED,
            pipeline: ExtractionPipeline::AI_ONLY,
        ));

        $this->assertSame('ai_vision', $result->strategy);
        $this->assertSame(0.8, $result->globalConfidence);
    }

    public function testLegacyCascadePipelineStillRunsTheNonAiTiers(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.95, strategyKey: 'pdf_parser'),
            $this->makeAiVisionDouble(confidence: 0.8),
            new StubExtractionStrategy(),
        ]);

        $result = $service->extract($this->makeDocument(
            userMode: ExtractionMode::BALANCED,
            pipeline: ExtractionPipeline::LEGACY_CASCADE,
        ));

        $this->assertSame('pdf_parser', $result->strategy);
    }

    public function testAiOnlyWithLocalOnlyModeIsSkippedByPolicyNotFailed(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 50, confidence: 0.8, strategyKey: 'ai_vision', isAi: true),
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument(
            userMode: ExtractionMode::LOCAL_ONLY,
            pipeline: ExtractionPipeline::AI_ONLY,
        );
        $result = $service->extract($document);

        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertSame(ExtractionFailureReason::LOCAL_ONLY_MODE, $result->failureReason);
        $this->assertSame(ExtractionStatus::SKIPPED_BY_POLICY, $document->getExtractionStatus());
        $this->assertSame(
            ExtractionFailureReason::LOCAL_ONLY_MODE,
            $document->getExtractionFailureReason(),
        );
    }

    public function testAiOnlyWithoutAnyVisionStrategyPersistsApiUnavailable(): void
    {
        // Mirrors production with an empty API key: AiVision::supports() returns
        // false, and with no Stub below it nothing runs at all.
        $service = new DataExtractionService([
            $this->makeAiVisionDouble(confidence: 0.8, supports: false),
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument(
            userMode: ExtractionMode::BALANCED,
            pipeline: ExtractionPipeline::AI_ONLY,
        );
        $result = $service->extract($document);

        $this->assertSame('ai_vision', $result->strategy);
        $this->assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus());
        $this->assertSame(
            ExtractionFailureReason::API_UNAVAILABLE,
            $document->getExtractionFailureReason(),
        );
    }

    public function testPipelineFallsBackToUploaderWhenDocumentHasNoCase(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.95, strategyKey: 'pdf_parser'),
            $this->makeAiVisionDouble(confidence: 0.8),
            new StubExtractionStrategy(),
        ]);

        // Wizard step 0 shape: the document exists before any LegalCase does.
        $user = new User();
        $user->setExtractionMode(ExtractionMode::BALANCED);
        $user->setExtractionPipeline(ExtractionPipeline::AI_ONLY);
        $user->setAiProcessingAgreementAt(new \DateTimeImmutable());
        $document = new Document();
        // What the wizard stores when the lawyer did not declare a type.
        $document->setDocumentType(DocumentType::ALT_DOCUMENT);
        $document->setUploadedBy($user);

        $this->assertSame('ai_vision', $service->extract($document)->strategy);
    }

    /**
     * The uploader leg is only a fallback. Once a case exists, the case owner
     * decides, because that is the account the extraction is billed and audited
     * against.
     */
    public function testPipelineComesFromTheCaseOwnerNotTheUploaderWhenACaseExists(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.95, strategyKey: 'pdf_parser'),
            $this->makeAiVisionDouble(confidence: 0.8),
            new StubExtractionStrategy(),
        ]);

        $owner = new User();
        $owner->setExtractionMode(ExtractionMode::BALANCED);
        $owner->setExtractionPipeline(ExtractionPipeline::AI_ONLY);
        $owner->setAiProcessingAgreementAt(new \DateTimeImmutable());

        $uploader = new User();
        $uploader->setExtractionMode(ExtractionMode::BALANCED);
        $uploader->setExtractionPipeline(ExtractionPipeline::LEGACY_CASCADE);
        $uploader->setAiProcessingAgreementAt(new \DateTimeImmutable());

        $case = new LegalCase();
        $case->setUser($owner);
        $document = new Document();
        // What the wizard stores when the lawyer did not declare a type.
        $document->setDocumentType(DocumentType::ALT_DOCUMENT);
        $document->setLegalCase($case);
        $document->setUploadedBy($uploader);

        $this->assertSame('ai_vision', $service->extract($document)->strategy);
    }

    /**
     * The policy skip must short-circuit before the loop. Running a strategy
     * and discarding its result would still have sent the document out.
     */
    public function testAiOnlyWithLocalOnlyModeRunsNoStrategyAtAll(): void
    {
        $recorder = $this->makeRecordingStrategy();
        $service = new DataExtractionService([$recorder, new StubExtractionStrategy()]);

        $service->extract($this->makeDocument(
            userMode: ExtractionMode::LOCAL_ONLY,
            pipeline: ExtractionPipeline::AI_ONLY,
        ));

        $this->assertSame(0, $recorder->extractCalls);
        $this->assertSame(0, $recorder->supportsCalls);
    }

    /**
     * The agreement is what covers sending a client's documents to a
     * third-party processor. An account that has never given it must not have
     * its documents leave the boundary, whatever the extraction mode says: the
     * mode is a quality preference, the agreement is the legal basis.
     *
     * This is the state a freshly registered account is in, since nothing on
     * the registration path touches either field.
     */
    public function testAiOnlyAccountWithoutTheProcessingAgreementDoesNotReachTheModel(): void
    {
        $recorder = $this->makeRecordingStrategy();
        $service = new DataExtractionService([$recorder]);

        $user = new User();
        $user->setExtractionPipeline(ExtractionPipeline::AI_ONLY);
        // Left at the registration defaults: AI is allowed by mode, and no
        // agreement has ever been recorded.
        self::assertFalse($user->hasAcceptedAiProcessing());

        $case = new LegalCase();
        $case->setUser($user);
        $document = new Document();
        // What the wizard stores when the lawyer did not declare a type.
        $document->setDocumentType(DocumentType::ALT_DOCUMENT);
        $document->setLegalCase($case);

        $service->extract($document);

        $this->assertSame(0, $recorder->extractCalls, 'No document may be sent before the agreement exists');
        $this->assertSame(ExtractionStatus::SKIPPED_BY_POLICY, $document->getExtractionStatus());
    }

    /**
     * SKIPPED_BY_POLICY belongs to AI_ONLY alone. A legacy account on
     * LOCAL_ONLY still has PdfParser and Stub to run, so its outcome is a plain
     * cascade result and the badge must not claim extraction was turned off.
     */
    public function testLegacyCascadeWithLocalOnlyModeIsNotSkippedByPolicy(): void
    {
        $service = new DataExtractionService([
            $this->makeAiVisionDouble(confidence: 0.9),
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument(
            userMode: ExtractionMode::LOCAL_ONLY,
            pipeline: ExtractionPipeline::LEGACY_CASCADE,
        );
        $result = $service->extract($document);

        $this->assertSame(StubExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus());
        $this->assertNull($document->getExtractionFailureReason());
    }

    /**
     * A retry writes over a previous attempt on the same Document, so a stale
     * cause must not survive a run that produced data.
     */
    public function testASuccessfulRunClearsAPreviouslyPersistedFailureReason(): void
    {
        $document = $this->makeDocument(
            userMode: ExtractionMode::BALANCED,
            pipeline: ExtractionPipeline::AI_ONLY,
        );
        $document->setExtractionFailureReason(ExtractionFailureReason::API_UNAVAILABLE);

        $service = new DataExtractionService([$this->makeAiVisionDouble(confidence: 0.8)]);
        $service->extract($document);

        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus());
        $this->assertNull($document->getExtractionFailureReason());
    }

    public function testSkipsAiBackedStrategiesInLocalOnlyMode(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.95, strategyKey: 'fake_ai', isAi: true),
            new StubExtractionStrategy(),
        ]);

        $result = $service->extract($this->makeDocument(userMode: ExtractionMode::LOCAL_ONLY));

        $this->assertSame('stub', $result->strategy, 'AI strategy must be skipped under LOCAL_ONLY');
    }

    public function testAllowsAiBackedStrategiesInBalancedMode(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.95, strategyKey: 'fake_ai', isAi: true),
            new StubExtractionStrategy(),
        ]);

        $result = $service->extract($this->makeDocument(userMode: ExtractionMode::BALANCED));

        $this->assertSame('fake_ai', $result->strategy);
    }

    public function testRespectsLegalCaseOverrideOverUserMode(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.95, strategyKey: 'fake_ai', isAi: true),
            new StubExtractionStrategy(),
        ]);

        // user = BALANCED (AI allowed) but case override = LOCAL_ONLY (AI denied)
        $result = $service->extract($this->makeDocument(
            userMode: ExtractionMode::BALANCED,
            caseOverride: ExtractionMode::LOCAL_ONLY,
        ));

        $this->assertSame('stub', $result->strategy, 'Case override must take precedence over user setting');
    }

    public function testPersistsResultOnDocument(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.85, strategyKey: 'fake_persist'),
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument();
        $service->extract($document);

        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus());
        $this->assertSame('fake_persist', $document->getExtractionStrategy());
        $this->assertSame('0.85', $document->getExtractionConfidence());
        $this->assertNotNull($document->getExtractedData());
        $this->assertSame('fake_persist', $document->getExtractedData()['strategy']);
    }

    public function testRespectsCustomThresholdParam(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.85, strategyKey: 'fake_85'),
            new StubExtractionStrategy(),
        ]);

        // threshold 0.95 → fake_85 below it → cascade falls to best-below-threshold (fake_85, NOT Stub)
        $result = $service->extract($this->makeDocument(), confidenceThreshold: 0.95);

        $this->assertSame('fake_85', $result->strategy, 'Best-below-threshold partial wins over zero-confidence Stub');
        $this->assertSame(0.85, $result->globalConfidence);
    }

    public function testPersistsBestBelowThresholdInsteadOfStub(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 100, confidence: 0.4, strategyKey: 'fake_partial'),
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument();
        $result = $service->extract($document); // default threshold 0.6

        $this->assertSame('fake_partial', $result->strategy);
        $this->assertSame(0.4, $result->globalConfidence);
        $this->assertSame('fake_partial', $document->getExtractionStrategy());
        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus(), 'Partial extraction (>0) marks COMPLETED');
    }

    public function testStubFallbackPersistsAsFailed(): void
    {
        $service = new DataExtractionService([
            $this->makeFake(priority: 80, supports: false, strategyKey: 'fake_no'),
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument();
        $service->extract($document);

        $this->assertSame('stub', $document->getExtractionStrategy());
        $this->assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus(),
            'Zero confidence (no data) must persist as FAILED, not COMPLETED');
        $this->assertSame('0.00', $document->getExtractionConfidence());
    }

    public function testSortsStrategiesByPriorityDescending(): void
    {
        $callOrder = [];

        $low = $this->makeSpyFake(priority: 30, confidence: 0.0, strategyKey: 'fake_low', spy: $callOrder);
        $high = $this->makeSpyFake(priority: 100, confidence: 0.0, strategyKey: 'fake_high', spy: $callOrder);
        $mid = $this->makeSpyFake(priority: 50, confidence: 0.0, strategyKey: 'fake_mid', spy: $callOrder);

        // Pass strategies in random order — orchestrator must sort by priority desc
        $service = new DataExtractionService([$low, $mid, $high, new StubExtractionStrategy()]);
        $service->extract($this->makeDocument());

        $this->assertSame(['fake_high', 'fake_mid', 'fake_low'], $callOrder);
    }

    // ----- B1 audit fix: orchestrator must survive unexpected throwables -----

    public function testCascadeContinuesWhenStrategyThrowsUnexpectedException(): void
    {
        // A strategy throwing a non-domain exception (e.g. corrupt-PDF fatal
        // from smalot, OOM during base64, parse error on truncated JSON) must
        // NOT abort the cascade. Critical for the upcoming Pas 2.6 async
        // messenger handler — one bad document cannot block the queue.
        $throwing = new class implements ExtractionStrategyInterface {
            public function supports(Document $document): bool { return true; }
            public function priority(): int { return 100; }
            public function isAiBacked(): bool { return false; }

            public function extract(Document $document): ExtractedDocumentData
            {
                throw new \RuntimeException('simulated smalot fatal');
            }
        };

        $service = new DataExtractionService([
            $throwing,
            $this->makeFake(priority: 70, confidence: 0.85, strategyKey: 'rescue'),
            new StubExtractionStrategy(),
        ]);

        $result = $service->extract($this->makeDocument(userMode: ExtractionMode::BALANCED));

        $this->assertSame('rescue', $result->strategy, 'Cascade must skip the throwing strategy and reach the next one');
        $this->assertSame(0.85, $result->globalConfidence);
    }

    public function testCascadeFallsBackToStubIfEveryStrategyThrows(): void
    {
        // All real strategies blow up → orchestrator still produces a result
        // (Stub) so the Document doesn't get stuck in PROCESSING.
        $thrower = static fn (int $priority, string $key) => new class($priority, $key) implements ExtractionStrategyInterface {
            public function __construct(private int $p, private string $k) {}
            public function supports(Document $document): bool { return true; }
            public function priority(): int { return $this->p; }
            public function isAiBacked(): bool { return false; }
            public function extract(Document $document): ExtractedDocumentData
            {
                throw new \LogicException("strategy {$this->k} crashed");
            }
        };

        $service = new DataExtractionService([
            $thrower(100, 'a'),
            $thrower(70, 'b'),
            new StubExtractionStrategy(),
        ]);

        $result = $service->extract($this->makeDocument(userMode: ExtractionMode::BALANCED));

        $this->assertSame('stub', $result->strategy);
        $this->assertSame(0.0, $result->globalConfidence);
    }

    // ----- dev knobs (forceStrategyKey + skipStrategyKeysCsv) -----

    public function testForceStrategyKeySkipsHigherPriorityStrategies(): void
    {
        // Without force: pdf_parser (priority 100) clears threshold and short-circuits.
        // With force: pdf_parser is skipped despite supporting the doc — only
        // ai_vision is even considered, exercising the lower-priority tier in
        // isolation. Dev/test ergonomic for AI tuning without contriving inputs.
        $service = new DataExtractionService(
            strategies: [
                $this->makeKeyedFake('pdf_parser', priority: 100, confidence: 0.95),
                $this->makeKeyedFake('ai_vision', priority: 50, confidence: 0.7),
                new StubExtractionStrategy(),
            ],
            forceStrategyKey: 'ai_vision',
        );

        $result = $service->extract($this->makeDocument(userMode: ExtractionMode::BALANCED));

        $this->assertSame('ai_vision', $result->strategy, 'Force knob must bypass higher-priority strategies');
        $this->assertSame(0.7, $result->globalConfidence);
    }

    public function testForceStrategyKeyEmptyStringIsTreatedAsNoOverride(): void
    {
        // services.yaml passes '%env(default::EXTRACTION_FORCE_STRATEGY)%' which
        // becomes '' when the env var is absent. The orchestrator must treat
        // '' identically to null — otherwise EVERY production deploy would
        // accidentally skip the entire cascade.
        $service = new DataExtractionService(
            strategies: [
                $this->makeKeyedFake('pdf_parser', priority: 100, confidence: 0.95),
                new StubExtractionStrategy(),
            ],
            forceStrategyKey: '',
        );

        $result = $service->extract($this->makeDocument(userMode: ExtractionMode::BALANCED));

        $this->assertSame('pdf_parser', $result->strategy);
    }

    public function testSkipStrategyKeysCsvSkipsListedTier(): void
    {
        // Mute pdf_parser — cascade jumps over it and ai_vision runs, even
        // though pdf_parser would have short-circuited normally. Useful for
        // benchmarking the AI tier's incremental contribution.
        $service = new DataExtractionService(
            strategies: [
                $this->makeKeyedFake('pdf_parser', priority: 100, confidence: 0.95),
                $this->makeKeyedFake('ai_vision', priority: 50, confidence: 0.8),
                new StubExtractionStrategy(),
            ],
            skipStrategyKeysCsv: 'pdf_parser',
        );

        $result = $service->extract($this->makeDocument(userMode: ExtractionMode::BALANCED));

        $this->assertSame('ai_vision', $result->strategy);
    }

    public function testSkipStrategyKeysCsvAcceptsMultipleCommaSeparated(): void
    {
        // CSV format `a,b` with optional whitespace must skip both.
        $service = new DataExtractionService(
            strategies: [
                $this->makeKeyedFake('pdf_parser', priority: 100, confidence: 0.95),
                $this->makeKeyedFake('ocr_text', priority: 70, confidence: 0.9),
                $this->makeKeyedFake('ai_vision', priority: 50, confidence: 0.8),
                new StubExtractionStrategy(),
            ],
            skipStrategyKeysCsv: 'pdf_parser, ocr_text',
        );

        $result = $service->extract($this->makeDocument(userMode: ExtractionMode::BALANCED));

        $this->assertSame('ai_vision', $result->strategy, 'Both listed tiers must be skipped');
    }

    public function testSkipWinsOverForceWhenBothSetForSameKey(): void
    {
        // Skip is the broader constraint — if a key is in both force AND skip,
        // skip must win (i.e. the strategy runs zero times). Documented contract
        // in DataExtractionService::__construct(). Without explicit coverage,
        // a future refactor could silently invert the precedence.
        $service = new DataExtractionService(
            strategies: [
                $this->makeKeyedFake('pdf_parser', priority: 100, confidence: 0.95),
                $this->makeKeyedFake('ai_vision', priority: 50, confidence: 0.8),
                new StubExtractionStrategy(),
            ],
            forceStrategyKey: 'ai_vision',
            skipStrategyKeysCsv: 'ai_vision',
        );

        $result = $service->extract($this->makeDocument(userMode: ExtractionMode::BALANCED));

        // Force=ai_vision blocks pdf_parser; skip=ai_vision blocks ai_vision;
        // Stub is also non-matching for force, so it gets blocked too → bestSoFar
        // is null → Stub fallback via the defensive branch.
        $this->assertSame('stub', $result->strategy);
        $this->assertSame(0.0, $result->globalConfidence);
    }

    public function testForceStrategyKeyBypassesSupportsForMatchedTier(): void
    {
        // Regression — observed in dev 2026-05-18 with EXTRACTION_FORCE_STRATEGY=ocr_text
        // on a PDF that had a text layer (`contract-multipage.pdf`). OcrText's
        // `supports()` returned false (its "PDF without text layer?" check is a
        // cascade-ordering optimization that defers to PdfParser), so the
        // cascade fell through to Stub and persisted FAILED. The force knob
        // existing for dev tier-isolation must override that optimization —
        // otherwise the knob is useless on exactly the documents you'd want to
        // test AI strategies against.
        $ocrText = new class implements ExtractionStrategyInterface {
            public const STRATEGY_KEY = 'ocr_text';
            public function supports(Document $document): bool { return false; }
            public function priority(): int { return 70; }
            public function isAiBacked(): bool { return true; }
            public function extract(Document $document): ExtractedDocumentData
            {
                return new ExtractedDocumentData(
                    sourceDocumentId: (int) $document->getId(),
                    strategy: self::STRATEGY_KEY,
                    globalConfidence: 0.85,
                    extractedAt: new \DateTimeImmutable(),
                );
            }
        };

        $service = new DataExtractionService(
            strategies: [
                $this->makeKeyedFake('pdf_parser', priority: 100, confidence: 0.95),
                $ocrText,
                new StubExtractionStrategy(),
            ],
            forceStrategyKey: 'ocr_text',
        );

        $result = $service->extract($this->makeDocument(userMode: ExtractionMode::BALANCED));

        $this->assertSame('ocr_text', $result->strategy, 'Force knob must run the tier even when supports() returns false');
        $this->assertSame(0.85, $result->globalConfidence);
    }

    public function testAnonymousStrategiesWithoutStrategyKeyConstantAreUnaffectedByDevKnobs(): void
    {
        // The defensive `defined(::STRATEGY_KEY)` check in the orchestrator
        // exists so test fakes without the constant don't crash when the env
        // knobs are set. Exercise that path: a fake without the const must
        // still run normally when force/skip are configured for OTHER keys.
        $service = new DataExtractionService(
            strategies: [
                $this->makeFake(priority: 100, confidence: 0.95, strategyKey: 'no_const_fake'),
                new StubExtractionStrategy(),
            ],
            forceStrategyKey: 'pdf_parser',
            skipStrategyKeysCsv: 'ocr_text',
        );

        $result = $service->extract($this->makeDocument(userMode: ExtractionMode::BALANCED));

        // forceStrategyKey='pdf_parser' filters out keys that don't match;
        // no_const_fake returns null STRATEGY_KEY, so the force check (which
        // compares against $strategyKey directly) skips it. Stub is filtered
        // the same way → defensive fallback returns a 0.0 Stub result.
        $this->assertSame('stub', $result->strategy);
    }

    /**
     * The two ways nothing can run under AI_ONLY need different answers: a
     * provider outage is worth retrying, an unset API key is not. Reported as
     * transient, a missing key would send every document of every account
     * through the full retry budget and then park it in the failed transport.
     */
    public function testAiOnlyWithoutAnApiKeyReportsAConfigurationCauseNotAnOutage(): void
    {
        $service = new DataExtractionService([$this->makeUnconfiguredVisionStrategy()]);

        $document = $this->makeDocument(
            userMode: ExtractionMode::BALANCED,
            pipeline: ExtractionPipeline::AI_ONLY,
        );
        $service->extract($document);

        $this->assertSame(
            ExtractionFailureReason::API_KEY_MISSING,
            $document->getExtractionFailureReason(),
        );
        $this->assertFalse($document->getExtractionFailureReason()->isTransient());
    }

    /**
     * The Stub ends every legacy cascade with an empty result, so a failed AI
     * tier and the Stub meet at zero confidence and the document is FAILED
     * either way. What must not happen is the empty result taking the
     * explanation down with it: the handler reads the failure reason to decide
     * whether another attempt is worth making, and the UI reads it to decide
     * whether to offer the lawyer a retry. Dropping it turns a provider outage,
     * which the next attempt would survive, into a document with no stated
     * cause and no way forward. Retrying is safe to ask for because an
     * exhausted budget now lands on a terminal FAILED of its own.
     */
    public function testAFailedAiTierKeepsItsCauseWhenTheStubAddsNothing(): void
    {
        $failingVision = new class implements ExtractionStrategyInterface {
            public const STRATEGY_KEY = 'ai_vision';

            public function priority(): int
            {
                return 50;
            }

            public function isAiBacked(): bool
            {
                return true;
            }

            public function supports(Document $document): bool
            {
                return true;
            }

            public function extract(Document $document): ExtractedDocumentData
            {
                return new ExtractedDocumentData(
                    sourceDocumentId: (int) $document->getId(),
                    strategy: self::STRATEGY_KEY,
                    globalConfidence: 0.0,
                    extractedAt: new \DateTimeImmutable(),
                    failureReason: ExtractionFailureReason::API_UNAVAILABLE,
                );
            }
        };

        $service = new DataExtractionService([$failingVision, new StubExtractionStrategy()]);

        $document = $this->makeDocument(
            userMode: ExtractionMode::BALANCED,
            pipeline: ExtractionPipeline::LEGACY_CASCADE,
        );
        $result = $service->extract($document);

        // Provenance names what actually ran and failed, rather than the empty
        // fallback that ran after it.
        $this->assertSame('ai_vision', $result->strategy);
        $this->assertSame(
            ExtractionFailureReason::API_UNAVAILABLE,
            $document->getExtractionFailureReason(),
        );
        $this->assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus());
    }

    /**
     * The reported bug, at the level where it was actually caused. A malformed
     * answer is transient, so the lawyer is meant to get a retry button and the
     * queue another attempt. Both are driven by the failure reason, and on a
     * legacy cascade the Stub used to erase it, which is why an invoice that
     * failed on auto-detected type left no way out but correcting the type by
     * hand.
     */
    public function testAMalformedAiAnswerStaysRetryableThroughTheStubFallback(): void
    {
        $malformedVision = new class implements ExtractionStrategyInterface {
            public const STRATEGY_KEY = 'ai_vision';

            public function priority(): int
            {
                return 50;
            }

            public function isAiBacked(): bool
            {
                return true;
            }

            public function supports(Document $document): bool
            {
                return true;
            }

            public function extract(Document $document): ExtractedDocumentData
            {
                return new ExtractedDocumentData(
                    sourceDocumentId: (int) $document->getId(),
                    strategy: self::STRATEGY_KEY,
                    globalConfidence: 0.0,
                    extractedAt: new \DateTimeImmutable(),
                    failureReason: ExtractionFailureReason::RESPONSE_MALFORMED,
                );
            }
        };

        $service = new DataExtractionService([$malformedVision, new StubExtractionStrategy()]);

        $document = $this->makeDocument(
            userMode: ExtractionMode::BALANCED,
            pipeline: ExtractionPipeline::LEGACY_CASCADE,
        );
        $result = $service->extract($document);

        $this->assertSame(ExtractionFailureReason::RESPONSE_MALFORMED, $result->failureReason);
        $this->assertSame(
            ExtractionFailureReason::RESPONSE_MALFORMED,
            $document->getExtractionFailureReason(),
        );
        $this->assertTrue(
            $document->getExtractionFailureReason()->isTransient(),
            'A retry is only offered for a transient cause, so the reason has to survive the cascade.',
        );
    }

    /**
     * A real vision strategy with no credentials, which is what an installation
     * without ANTHROPIC_API_KEY has. The double used elsewhere cannot stand in:
     * the orchestrator asks the concrete strategy whether it is configured.
     */
    private function makeUnconfiguredVisionStrategy(): AiVisionExtractionStrategy
    {
        $noLimit = new RateLimiterFactory(
            ['id' => 'test_no_limit', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );

        return new AiVisionExtractionStrategy(
            llmClient: $this->createStub(LlmClientInterface::class),
            promptRegistry: ExtractionPrompts::registry(),
            auditLogService: $this->createStub(AuditLogService::class),
            extractionAiVisionLimiter: $noLimit,
            extractionAiVisionBurstLimiter: $noLimit,
            uploadsDir: sys_get_temp_dir(),
            anthropicApiKey: '',
        );
    }

    // ----- helpers -----

    /**
     * The pipeline defaults to LEGACY_CASCADE because most tests in this class
     * exercise cascade mechanics (ordering, thresholds, the dev knobs), which
     * only exist on that pipeline. AI_ONLY behaviour has its own tests below and
     * passes the value explicitly.
     */
    private function makeDocument(
        ExtractionMode $userMode = ExtractionMode::LOCAL_ONLY,
        ?ExtractionMode $caseOverride = null,
        ExtractionPipeline $pipeline = ExtractionPipeline::LEGACY_CASCADE,
    ): Document {
        $user = new User();
        $user->setExtractionMode($userMode);
        $user->setExtractionPipeline($pipeline);
        // The AI processing agreement is a precondition for every AI-backed
        // strategy. These tests vary the mode and the pipeline, so the agreement
        // is held constant at "given"; the tests that vary it say so explicitly.
        $user->setAiProcessingAgreementAt(new \DateTimeImmutable());

        $case = new LegalCase();
        $case->setUser($user);
        if ($caseOverride !== null) {
            $case->setExtractionModeOverride($caseOverride);
        }

        $document = new Document();
        // What the wizard stores when the lawyer did not declare a type.
        $document->setDocumentType(DocumentType::ALT_DOCUMENT);
        $document->setLegalCase($case);

        return $document;
    }

    /**
     * An ai_vision double that counts how often the orchestrator touched it, so
     * a test can assert a strategy was never reached rather than only that its
     * result was discarded.
     */
    private function makeRecordingStrategy(): ExtractionStrategyInterface
    {
        return new class implements ExtractionStrategyInterface {
            public const STRATEGY_KEY = 'ai_vision';

            public int $supportsCalls = 0;
            public int $extractCalls = 0;

            public function priority(): int
            {
                return 50;
            }

            public function isAiBacked(): bool
            {
                return true;
            }

            public function supports(Document $document): bool
            {
                ++$this->supportsCalls;

                return true;
            }

            public function extract(Document $document): ExtractedDocumentData
            {
                ++$this->extractCalls;

                return new ExtractedDocumentData(
                    sourceDocumentId: (int) $document->getId(),
                    strategy: self::STRATEGY_KEY,
                    globalConfidence: 0.9,
                    extractedAt: new \DateTimeImmutable(),
                );
            }
        };
    }

    /**
     * Stand-in for AiVisionExtractionStrategy. Unlike {@see self::makeFake()} it
     * declares the STRATEGY_KEY class constant, which is what the AI_ONLY filter
     * reads; a double without it is treated as an unknown tier and skipped.
     */
    private function makeAiVisionDouble(float $confidence, bool $supports = true): ExtractionStrategyInterface
    {
        return new class($confidence, $supports) implements ExtractionStrategyInterface {
            public const STRATEGY_KEY = 'ai_vision';

            public function __construct(
                private float $confidenceValue,
                private bool $supportsValue,
            ) {}

            public function priority(): int
            {
                return 50;
            }

            public function isAiBacked(): bool
            {
                return true;
            }

            public function supports(Document $document): bool
            {
                return $this->supportsValue;
            }

            public function extract(Document $document): ExtractedDocumentData
            {
                return new ExtractedDocumentData(
                    sourceDocumentId: (int) $document->getId(),
                    strategy: self::STRATEGY_KEY,
                    globalConfidence: $this->confidenceValue,
                    extractedAt: new \DateTimeImmutable(),
                );
            }
        };
    }

    private function makeFake(
        int $priority,
        float $confidence = 0.0,
        string $strategyKey = 'fake',
        bool $supports = true,
        bool $isAi = false,
    ): ExtractionStrategyInterface {
        return new class($priority, $confidence, $strategyKey, $supports, $isAi) implements ExtractionStrategyInterface {
            public function __construct(
                private int $priorityValue,
                private float $confidenceValue,
                private string $strategyKey,
                private bool $supportsValue,
                private bool $aiValue,
            ) {}

            public function supports(Document $document): bool { return $this->supportsValue; }
            public function priority(): int { return $this->priorityValue; }
            public function isAiBacked(): bool { return $this->aiValue; }

            public function extract(Document $document): ExtractedDocumentData
            {
                return new ExtractedDocumentData(
                    sourceDocumentId: (int) $document->getId(),
                    strategy: $this->strategyKey,
                    globalConfidence: $this->confidenceValue,
                    extractedAt: new \DateTimeImmutable(),
                );
            }
        };
    }

    /**
     * Builds a fake strategy that DECLARES a `STRATEGY_KEY` constant matching
     * the orchestrator's force/skip dev-knob lookup. The plain {@see makeFake}
     * uses a property instead of a constant — that's intentional for tests
     * that exercise the defensive `defined(::STRATEGY_KEY)` path, but the dev
     * knobs themselves require the constant.
     *
     * One named anonymous-class fixture per known production key, since PHP
     * doesn't allow parameterising constants on anonymous classes.
     */
    private function makeKeyedFake(string $key, int $priority, float $confidence): ExtractionStrategyInterface
    {
        return match ($key) {
            'pdf_parser' => new class($priority, $confidence) implements ExtractionStrategyInterface {
                public const STRATEGY_KEY = 'pdf_parser';
                public function __construct(private int $p, private float $c) {}
                public function supports(Document $document): bool { return true; }
                public function priority(): int { return $this->p; }
                public function isAiBacked(): bool { return false; }
                public function extract(Document $document): ExtractedDocumentData
                {
                    return new ExtractedDocumentData(
                        sourceDocumentId: (int) $document->getId(),
                        strategy: self::STRATEGY_KEY,
                        globalConfidence: $this->c,
                        extractedAt: new \DateTimeImmutable(),
                    );
                }
            },
            'ocr_text' => new class($priority, $confidence) implements ExtractionStrategyInterface {
                public const STRATEGY_KEY = 'ocr_text';
                public function __construct(private int $p, private float $c) {}
                public function supports(Document $document): bool { return true; }
                public function priority(): int { return $this->p; }
                public function isAiBacked(): bool { return true; }
                public function extract(Document $document): ExtractedDocumentData
                {
                    return new ExtractedDocumentData(
                        sourceDocumentId: (int) $document->getId(),
                        strategy: self::STRATEGY_KEY,
                        globalConfidence: $this->c,
                        extractedAt: new \DateTimeImmutable(),
                    );
                }
            },
            'ai_vision' => new class($priority, $confidence) implements ExtractionStrategyInterface {
                public const STRATEGY_KEY = 'ai_vision';
                public function __construct(private int $p, private float $c) {}
                public function supports(Document $document): bool { return true; }
                public function priority(): int { return $this->p; }
                public function isAiBacked(): bool { return true; }
                public function extract(Document $document): ExtractedDocumentData
                {
                    return new ExtractedDocumentData(
                        sourceDocumentId: (int) $document->getId(),
                        strategy: self::STRATEGY_KEY,
                        globalConfidence: $this->c,
                        extractedAt: new \DateTimeImmutable(),
                    );
                }
            },
            default => throw new \LogicException("Unknown strategy key in test fixture: {$key}"),
        };
    }

    /**
     * @param list<string> $spy reference to call-order accumulator
     */
    private function makeSpyFake(
        int $priority,
        float $confidence,
        string $strategyKey,
        array &$spy,
    ): ExtractionStrategyInterface {
        return new class($priority, $confidence, $strategyKey, $spy) implements ExtractionStrategyInterface {
            /** @param list<string> $spy */
            public function __construct(
                private int $priorityValue,
                private float $confidenceValue,
                private string $strategyKey,
                private array &$spy,
            ) {}

            public function supports(Document $document): bool
            {
                $this->spy[] = $this->strategyKey;
                return true;
            }

            public function priority(): int { return $this->priorityValue; }
            public function isAiBacked(): bool { return false; }

            public function extract(Document $document): ExtractedDocumentData
            {
                return new ExtractedDocumentData(
                    sourceDocumentId: (int) $document->getId(),
                    strategy: $this->strategyKey,
                    globalConfidence: $this->confidenceValue,
                    extractedAt: new \DateTimeImmutable(),
                );
            }
        };
    }
}
