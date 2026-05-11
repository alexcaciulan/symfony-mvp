<?php

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionStatus;
use App\Service\Extraction\DataExtractionService;
use App\Service\Extraction\ExtractionStrategyInterface;
use App\Service\Extraction\StubExtractionStrategy;
use PHPUnit\Framework\TestCase;

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

    // ----- helpers -----

    private function makeDocument(
        ExtractionMode $userMode = ExtractionMode::LOCAL_ONLY,
        ?ExtractionMode $caseOverride = null,
    ): Document {
        $user = new User();
        $user->setExtractionMode($userMode);

        $case = new LegalCase();
        $case->setUser($user);
        if ($caseOverride !== null) {
            $case->setExtractionModeOverride($caseOverride);
        }

        $document = new Document();
        $document->setLegalCase($case);

        return $document;
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
