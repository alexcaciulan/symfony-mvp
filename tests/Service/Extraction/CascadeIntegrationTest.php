<?php

namespace App\Tests\Service\Extraction;

use App\DTO\Ocr\OcrResult;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionStatus;
use App\Service\AuditLogService;
use App\Service\Extraction\AiVisionExtractionStrategy;
use App\Service\Extraction\DataExtractionService;
use App\Service\Extraction\OcrTextExtractionStrategy;
use App\Service\Extraction\PdfParserExtractionStrategy;
use App\Service\Extraction\StubExtractionStrategy;
use App\Service\Llm\AnthropicApiClient;
use App\Service\Ocr\OcrServiceInterface;
use App\Service\Ocr\TesseractOcrService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * End-to-end cascade integration tests with REAL strategy implementations
 * (no fakes / mocks) and the static realistic PDF fixtures from
 * tests/fixtures/extraction/.
 *
 * The other test classes in this folder cover narrower concerns:
 *   - DataExtractionServiceTest   — orchestrator with anonymous fake strategies
 *                                   (no I/O, no PDF) — tests cascade logic.
 *   - PdfParserExtractionStrategyTest — PdfParser unit tests with trivial
 *                                       runtime-rendered PDFs.
 *   - PdfParserExtractionStrategyIntegrationTest — PdfParser alone against
 *                                                  realistic static fixtures.
 *
 * This class wires it all together: orchestrator + PdfParser + Stub +
 * realistic PDF fixtures. It validates the contract that:
 *   - A text-based PDF cascades to PdfParser (priority 100), short-circuits.
 *   - A corrupt or text-poor PDF falls through to Stub (priority 10) and is
 *     persisted as FAILED (globalConfidence == 0.0 → status FAILED per Pas 2.5.2).
 *   - LOCAL_ONLY mode does not affect this layer (PdfParser + Stub are both
 *     non-AI), but is still resolved correctly via Document → LegalCase → User.
 */
class CascadeIntegrationTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../fixtures/extraction';

    private DataExtractionService $orchestrator;

    protected function setUp(): void
    {
        $strategies = [
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            new StubExtractionStrategy(),
        ];
        $this->orchestrator = new DataExtractionService($strategies);
    }

    public function testRealisticInvoiceCascadesToPdfParserAndShortCircuits(): void
    {
        $document = $this->makeDocument('invoice-realistic.pdf');

        $result = $this->orchestrator->extract($document);

        // PdfParser (priority 100) wins — Stub never runs.
        $this->assertSame(PdfParserExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertGreaterThan(0.6, $result->globalConfidence);

        // Document was mutated and persisted as COMPLETED (confidence > 0).
        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus());
        $this->assertSame('pdf_parser', $document->getExtractionStrategy());
        $this->assertNotNull($document->getExtractedData());

        // Real fields ended up persisted on the JSON column.
        $this->assertSame('15193236', $document->getExtractedData()['creditor']['cui']);
        $this->assertSame('14186770', $document->getExtractedData()['debtor']['cui']);
    }

    public function testCorruptPdfCascadesAllTheWayToStub(): void
    {
        // First write a corrupt PDF in the fixtures dir (the static fixtures are
        // all valid; we need an explicit broken case to exercise Stub fallback).
        $corruptPath = self::FIXTURES_DIR . '/_cascade-test-corrupt.pdf';
        file_put_contents($corruptPath, 'NOT A PDF AT ALL');

        try {
            $document = $this->makeDocument('_cascade-test-corrupt.pdf');

            $result = $this->orchestrator->extract($document);

            // PdfParser.supports() returned false (smalot threw) → cascade fell to Stub.
            $this->assertSame(StubExtractionStrategy::STRATEGY_KEY, $result->strategy);
            $this->assertSame(0.0, $result->globalConfidence);

            // Stub fallback with confidence 0 must persist as FAILED, not COMPLETED.
            $this->assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus());
            $this->assertSame('stub', $document->getExtractionStrategy());
        } finally {
            @unlink($corruptPath);
        }
    }

    public function testCascadeExtractsCnpFromLoanContract(): void
    {
        $document = $this->makeDocument('loan-individual.pdf');

        $result = $this->orchestrator->extract($document);

        $this->assertSame('pdf_parser', $result->strategy);
        $this->assertNotNull($result->debtor);
        $this->assertSame('1980715221232', $result->debtor->personalId);

        // Persisted JSON contains the CNP — Pas 2.5.4 will introduce PiiMasker
        // for AI prompts, but at this layer raw values are kept (the data never
        // leaves the server in this strategy chain).
        $persisted = $document->getExtractedData();
        $this->assertSame('1980715221232', $persisted['debtor']['personalId']);
    }

    public function testCascadeRespectsUserLocalOnlyMode(): void
    {
        // Both PdfParser and Stub are non-AI, so LOCAL_ONLY behaves the same as
        // BALANCED for this layer. We still want to assert the orchestrator
        // resolves the mode without errors when the user opts out of AI.
        $document = $this->makeDocument('invoice-realistic.pdf', userMode: ExtractionMode::LOCAL_ONLY);

        $result = $this->orchestrator->extract($document);

        $this->assertSame('pdf_parser', $result->strategy);
        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus());
    }

    public function testCascadeAcrossAllRealisticFixturesProducesUsefulData(): void
    {
        foreach (['invoice-realistic.pdf', 'contract-multipage.pdf', 'loan-individual.pdf'] as $filename) {
            $document = $this->makeDocument($filename);

            $result = $this->orchestrator->extract($document);

            $this->assertSame(
                'pdf_parser',
                $result->strategy,
                "Realistic fixture {$filename} must cascade to PdfParser, not Stub",
            );
            $this->assertSame(
                ExtractionStatus::COMPLETED,
                $document->getExtractionStatus(),
                "Realistic fixture {$filename} must persist as COMPLETED",
            );
            $this->assertNotNull($result->creditor, "Creditor should be extracted from {$filename}");
            $this->assertSame('15193236', $result->creditor->cui, "Creditor CUI mismatch on {$filename}");
        }
    }

    // ---------- Pas 2.5.7 — cascade with OcrTextExtractionStrategy ----------

    public function testScannedImageCascadesToOcrTextStrategy(): void
    {
        // Build a cascade orchestrator that has all three strategies registered
        // with realistic priority ordering: PdfParser(100) > OcrText(70) > Stub(10).
        // The image MIME forces PdfParser.supports() = false → OcrText takes over.
        $audit = $this->captureAuditLogService();
        $orchestrator = new DataExtractionService([
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            $this->makeOcrTextStrategyReplaying('anthropic-success-with-pii.json', $audit),
            new StubExtractionStrategy(),
        ]);

        // Document is image/png → PdfParser drops out, OcrText is the next candidate.
        $document = $this->makeDocument('scan.png', mime: 'image/png', userMode: ExtractionMode::BALANCED);

        $result = $orchestrator->extract($document);

        $this->assertSame(OcrTextExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertGreaterThanOrEqual(0.6, $result->globalConfidence, 'OcrText must short-circuit above the cascade threshold');
        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus());
        $this->assertSame('ocr_text', $document->getExtractionStrategy());
        // Audit log was written exactly once with AI category — no double-billing.
        $this->assertCount(1, $audit->loggedCalls);
        $this->assertSame(AuditLogService::CATEGORY_AI_EXTRACTION, $audit->loggedCalls[0]['category']);
    }

    public function testLocalOnlyModeSkipsOcrTextStrategyAndFallsToStub(): void
    {
        // Same wiring as above, but the user's extractionMode is LOCAL_ONLY.
        // The orchestrator must filter out OcrText (isAiBacked() === true) at
        // selection time — the AI must NEVER be called, even with a working
        // mock — so we wire a MockHttpClient that EXPLODES if hit.
        $audit = $this->captureAuditLogService();
        $strategy = $this->makeOcrTextStrategyWithExplodingClient($audit);

        $orchestrator = new DataExtractionService([
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            $strategy,
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument('scan.png', mime: 'image/png', userMode: ExtractionMode::LOCAL_ONLY);

        $result = $orchestrator->extract($document);

        // Cascade: PdfParser (no — wrong mime) → OcrText (skipped — AI-backed under LOCAL_ONLY)
        // → Stub (always supports) → status FAILED because confidence == 0.
        $this->assertSame(StubExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus());
        // No audit log was written (OcrText never reached its audit step).
        $this->assertSame([], $audit->loggedCalls);
    }

    // ---------- Pas 2.5.8 — cascade complet 4 trepte cu AiVisionExtractionStrategy ----------

    public function testCompleteCascadeFlowsThroughAllFourTiersToAiVision(): void
    {
        // The full 4-tier cascade in priority order:
        //   PdfParser(100) → OcrText(70) → AiVision(50) → Stub(10)
        // We force the cascade to reach AiVision by:
        //   1. Using a PNG (so PdfParser.supports() = false)
        //   2. Stubbing OcrText's OCR layer to return text too short to clear
        //      the MIN_OCR_TEXT_LENGTH gate → OcrText returns 0.0 confidence,
        //      orchestrator continues
        //   3. AiVision's Anthropic mock replays the rich vision fixture →
        //      globalConfidence 0.91 → short-circuits before Stub
        $audit = $this->captureAuditLogService();
        $orchestrator = new DataExtractionService([
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            $this->makeOcrTextStrategyWithLowQualityOcr($audit),
            $this->makeAiVisionStrategyReplaying('anthropic-success-vision-rich.json', $audit),
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument('clean-text.png', mime: 'image/png', userMode: ExtractionMode::BALANCED);

        $result = $orchestrator->extract($document);

        // AiVision wins — it's the first strategy whose globalConfidence
        // crosses the cascade short-circuit threshold (0.6).
        $this->assertSame(AiVisionExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertSame(0.91, $result->globalConfidence);
        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus());
        $this->assertSame('ai_vision', $document->getExtractionStrategy());
        // Persisted JSON keeps the rich extraction payload — wizard pre-fills from this.
        $persisted = $document->getExtractedData();
        $this->assertSame('Alpha Servicii Comerciale SRL', $persisted['creditor']['name']);
        $this->assertSame(7532.70, $persisted['claim']['amount']);
        // Two audit calls: one from OcrText's quality-gate skip path (which
        // doesn't audit because we never reached the post-AI section) and
        // one from AiVision's success path. OcrText returning 0-confidence
        // BEFORE the AI call means it does NOT audit — so we expect exactly
        // one entry, all from AiVision.
        $this->assertCount(1, $audit->loggedCalls);
        $this->assertSame('ai_vision', $audit->loggedCalls[0]['newData']['strategy']);
    }

    public function testLocalOnlyModeSkipsBothAiStrategiesAndFallsToStub(): void
    {
        // With LOCAL_ONLY, the orchestrator's filter on isAiBacked() === true
        // must drop BOTH OcrText and AiVision. Vision in particular sends raw
        // binary to Anthropic, which is exactly what LOCAL_ONLY users opt out
        // of. We wire MockHttpClients that explode if hit — getting through
        // the test green means the filter held for both strategies.
        $audit = $this->captureAuditLogService();
        $orchestrator = new DataExtractionService([
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            $this->makeOcrTextStrategyWithExplodingClient($audit),
            $this->makeAiVisionStrategyWithExplodingClient($audit),
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument('clean-text.png', mime: 'image/png', userMode: ExtractionMode::LOCAL_ONLY);

        $result = $orchestrator->extract($document);

        // PdfParser drops on PNG mime, OcrText + AiVision filtered out by
        // LOCAL_ONLY → Stub. Status FAILED (confidence 0).
        $this->assertSame(StubExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus());
        $this->assertSame([], $audit->loggedCalls, 'No AI audit must be written under LOCAL_ONLY');
    }

    private function makeAiVisionStrategyReplaying(
        string $fixtureFilename,
        AuditLogService $audit,
    ): AiVisionExtractionStrategy {
        $body = file_get_contents(__DIR__ . '/../../fixtures/llm/' . $fixtureFilename);
        if ($body === false) {
            self::fail("Fixture {$fixtureFilename} unreadable in cascade test");
        }
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]));

        return new AiVisionExtractionStrategy(
            llmClient: new AnthropicApiClient(
                httpClient: $http,
                anthropicApiKey: 'sk-ant-cascade-vision',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $audit,
            extractionAiVisionLimiter: $this->noLimitFactory(),
            // The fixture binary lives in tests/fixtures/ocr/clean-text.png —
            // FIXTURES_DIR is tests/fixtures/extraction, so we point uploadsDir
            // there and use a sibling-dir-relative name. Cleaner alternative
            // would be a per-test tmp dir, but reusing the existing real PNG
            // keeps the test self-contained and avoids generating binaries
            // at runtime.
            uploadsDir: self::FIXTURES_DIR . '/../ocr',
            anthropicApiKey: 'sk-ant-cascade-vision',
            logger: new NullLogger(),
        );
    }

    private function makeAiVisionStrategyWithExplodingClient(AuditLogService $audit): AiVisionExtractionStrategy
    {
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \LogicException(
                'AnthropicApiClient (vision) must NOT be invoked under LOCAL_ONLY mode — '
                . 'orchestrator should have filtered the AI-backed strategy upstream',
            );
        });

        return new AiVisionExtractionStrategy(
            llmClient: new AnthropicApiClient(
                httpClient: $http,
                anthropicApiKey: 'sk-ant-cascade-vision',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $audit,
            extractionAiVisionLimiter: $this->noLimitFactory(),
            uploadsDir: self::FIXTURES_DIR . '/../ocr',
            anthropicApiKey: 'sk-ant-cascade-vision',
            logger: new NullLogger(),
        );
    }

    private function makeOcrTextStrategyWithLowQualityOcr(AuditLogService $audit): OcrTextExtractionStrategy
    {
        // Fake OCR returns text too short for the strategy's MIN_OCR_TEXT_LENGTH
        // gate (200 chars). The strategy bails out at the quality gate WITHOUT
        // calling the AI, so we wire an exploding HTTP client to prove the
        // gate triggered (any AI call would crash the test). Returns
        // zero-confidence DTO — orchestrator continues to AiVision.
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \LogicException(
                'OcrText AI must NOT be called when OCR quality gate triggers — '
                . 'cascade test relies on the gate to flow through to AiVision',
            );
        });
        $shortTextOcr = new class implements OcrServiceInterface {
            public function extractText(string $absolutePath): OcrResult
            {
                return new OcrResult('abc', 0.1, 1); // 3 chars, conf 0.1 → quality gate trips
            }
        };

        return new OcrTextExtractionStrategy(
            ocrService: $shortTextOcr,
            llmClient: new AnthropicApiClient(
                httpClient: $http,
                anthropicApiKey: 'sk-ant-cascade-ocr',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $audit,
            extractionAiTextLimiter: $this->noLimitFactory(),
            uploadsDir: self::FIXTURES_DIR . '/../ocr',
            anthropicApiKey: 'sk-ant-cascade-ocr',
            logger: new NullLogger(),
        );
    }

    private function makeOcrTextStrategyReplaying(
        string $fixtureFilename,
        AuditLogService $audit,
    ): OcrTextExtractionStrategy {
        $body = file_get_contents(__DIR__ . '/../../fixtures/llm/' . $fixtureFilename);
        if ($body === false) {
            self::fail("Fixture {$fixtureFilename} unreadable in cascade test");
        }
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]));

        return new OcrTextExtractionStrategy(
            ocrService: $this->fakeOcrServiceWithRealisticText(),
            llmClient: new AnthropicApiClient(
                httpClient: $http,
                anthropicApiKey: 'sk-ant-cascade-test',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $audit,
            extractionAiTextLimiter: $this->noLimitFactory(),
            uploadsDir: '/tmp/cascade-tests-not-touched',
            anthropicApiKey: 'sk-ant-cascade-test',
            logger: new NullLogger(),
        );
    }

    private function makeOcrTextStrategyWithExplodingClient(AuditLogService $audit): OcrTextExtractionStrategy
    {
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \LogicException(
                'AnthropicApiClient must NOT be invoked under LOCAL_ONLY mode — '
                . 'orchestrator should have filtered the AI-backed strategy upstream',
            );
        });

        return new OcrTextExtractionStrategy(
            ocrService: $this->fakeOcrServiceWithRealisticText(),
            llmClient: new AnthropicApiClient(
                httpClient: $http,
                anthropicApiKey: 'sk-ant-cascade-test',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $audit,
            extractionAiTextLimiter: $this->noLimitFactory(),
            uploadsDir: '/tmp',
            anthropicApiKey: 'sk-ant-cascade-test',
            logger: new NullLogger(),
        );
    }

    private function fakeOcrServiceWithRealisticText(): OcrServiceInterface
    {
        // Same shape as OcrTextExtractionStrategyIntegrationTest's fake — kept
        // private to each test class because the cascade test deliberately
        // doesn't share infrastructure with the integration test (changes to
        // one shouldn't silently shift the other's expectations).
        $text = <<<TEXT
CONTRACT DE PRESTĂRI SERVICII NR. 042/2026
Creditor: SC Foo Consulting SRL, CUI RO15193236, cont RO49AAAA1B31007593840000.
Debitor: Popescu Maria, CNP 1980715221232.
Suma totală: 6.009,50 RON, scadenta la data de 15.06.2026.
TEXT;

        return new class($text) implements OcrServiceInterface {
            public function __construct(private string $text) {}

            public function extractText(string $absolutePath): OcrResult
            {
                return new OcrResult($this->text, 0.92, 1);
            }
        };
    }

    private function captureAuditLogService(): AuditLogService
    {
        return new class extends AuditLogService {
            /** @var array<int, array<string, mixed>> */
            public array $loggedCalls = [];

            public function __construct() {}

            public function log(
                string $action,
                string $entityType,
                string $entityId,
                ?array $oldData = null,
                ?array $newData = null,
                ?string $category = null,
            ): \App\Entity\AuditLog {
                $this->loggedCalls[] = [
                    'action' => $action,
                    'category' => $category,
                    'newData' => $newData,
                ];

                return new \App\Entity\AuditLog();
            }
        };
    }

    private function noLimitFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'cascade_no_limit', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
    }

    private function makeDocument(
        string $filename,
        string $mime = 'application/pdf',
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
        $document->setOriginalFilename($filename);
        $document->setStoredFilename($filename);
        $document->setFileSize(1024);
        $document->setMimeType($mime);

        return $document;
    }

    // ---------- cascade gap-coverage ----------

    public function testCascadeAttemptsAllStrategiesAndPersistsAsFailedWhenAllReturnZero(): void
    {
        // Hardest negative path: every real strategy gets a chance and every
        // one returns zero-confidence. Image PNG → PdfParser drops. OcrText
        // quality gate trips on text='abc'. AiVision throws an LlmException
        // on the AI call. Stub always runs and returns 0. The orchestrator's
        // bestSoFar tracking must persist a status=FAILED result rather than
        // crash.
        $audit = $this->captureAuditLogService();

        $explodingFromStubReplacement = new MockHttpClient(static function (): MockResponse {
            // For OcrText: this MockHttpClient should never be hit because the
            // quality gate triggers BEFORE the AI call. If it ever is, we fail
            // loudly so the test exposes a regression.
            throw new \LogicException('OcrText AI must not run when quality gate triggered');
        });
        $shortTextOcr = new class implements OcrServiceInterface {
            public function extractText(string $absolutePath): OcrResult
            {
                return new OcrResult('abc', 0.1, 1);
            }
        };
        $ocrText = new OcrTextExtractionStrategy(
            ocrService: $shortTextOcr,
            llmClient: new AnthropicApiClient(
                httpClient: $explodingFromStubReplacement,
                anthropicApiKey: 'sk-fail-test',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $audit,
            extractionAiTextLimiter: $this->noLimitFactory(),
            uploadsDir: self::FIXTURES_DIR . '/../ocr',
            anthropicApiKey: 'sk-fail-test',
            logger: new NullLogger(),
        );
        // AiVision throws via 503-shaped MockResponse → AnthropicApiClient
        // raises LlmException → strategy catches and returns zero-confidence.
        $aiVision = new AiVisionExtractionStrategy(
            llmClient: new AnthropicApiClient(
                httpClient: new MockHttpClient(static fn (): MockResponse => new MockResponse(
                    '{"type":"error","error":{"type":"overloaded_error","message":"AI down"}}',
                    ['http_code' => 503],
                )),
                anthropicApiKey: 'sk-fail-test',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $audit,
            extractionAiVisionLimiter: $this->noLimitFactory(),
            uploadsDir: self::FIXTURES_DIR . '/../ocr',
            anthropicApiKey: 'sk-fail-test',
            logger: new NullLogger(),
        );

        $orchestrator = new DataExtractionService([
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            $ocrText,
            $aiVision,
            new StubExtractionStrategy(),
        ]);
        $document = $this->makeDocument('clean-text.png', mime: 'image/png', userMode: ExtractionMode::BALANCED);

        $result = $orchestrator->extract($document);

        // Conf 0 across the board → status FAILED. The persisted strategy is
        // whichever non-Stub strategy produced the first zero-confidence DTO
        // (OcrText here, by priority order). The exact strategy key is
        // implementation-detail; what matters legally and to the wizard is
        // the FAILED status + zero confidence — manual entry required.
        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus());
        // No AI extraction was successfully completed → no AI_EXTRACTION audit.
        // (OcrText skipped at quality gate before audit; AiVision threw before audit.)
        $this->assertSame([], $audit->loggedCalls, 'No AI audit must be written when every AI step fails');
    }

    public function testCascadePersistsPdfParserResultBelowRaisedThresholdInsteadOfStub(): void
    {
        // best-so-far tracking with REAL strategies. Threshold raised to 0.999
        // so PdfParser's normal ~0.7-0.95 confidence falls below it; cascade
        // continues. The next strategies (OcrText, AiVision) won't help on a
        // text PDF — OcrText.supports() returns false because the PDF has a
        // text layer — so PdfParser remains the bestSoFar and is persisted
        // even though it didn't clear the threshold. Status COMPLETED
        // because confidence > 0.
        $audit = $this->captureAuditLogService();
        $orchestrator = new DataExtractionService([
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            $this->makeOcrTextStrategyReplaying('anthropic-success-with-pii.json', $audit),
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument('invoice-realistic.pdf');

        $result = $orchestrator->extract($document, confidenceThreshold: 0.999);

        // PdfParser ran, returned a real (sub-0.999) confidence, and orchestrator
        // persisted it as bestSoFar — NOT the Stub fallback, NOT a 0.0 default.
        $this->assertSame('pdf_parser', $result->strategy);
        $this->assertGreaterThan(0.0, $result->globalConfidence);
        $this->assertLessThan(0.999, $result->globalConfidence);
        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus(), 'Partial result still counts as COMPLETED');
        // Document.extractedData carries the PdfParser-extracted CUI — the wizard
        // can pre-fill from this even though confidence is below threshold.
        $this->assertSame('15193236', $document->getExtractedData()['creditor']['cui']);
    }

    public function testLegalCaseOverrideForcesAiInRealCascadeEvenIfUserModeIsLocalOnly(): void
    {
        // GDPR opt-in escape hatch — a lawyer who set their account-default to
        // LOCAL_ONLY can still flip a SINGLE case to BALANCED via the case
        // override. The orchestrator must respect that override and route the
        // document through AI strategies.
        $audit = $this->captureAuditLogService();
        $orchestrator = new DataExtractionService([
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            $this->makeOcrTextStrategyReplaying('anthropic-success-with-pii.json', $audit),
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument(
            'scan.png',
            mime: 'image/png',
            userMode: ExtractionMode::LOCAL_ONLY,
            caseOverride: ExtractionMode::BALANCED,
        );

        $result = $orchestrator->extract($document);

        // OcrText took over (image mime + BALANCED override) and short-circuited.
        $this->assertSame(OcrTextExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertGreaterThanOrEqual(0.6, $result->globalConfidence);
        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus());
        // Audit shows the AI was invoked under the case-level override.
        $this->assertCount(1, $audit->loggedCalls);
        $this->assertSame(AuditLogService::CATEGORY_AI_EXTRACTION, $audit->loggedCalls[0]['category']);
    }

    public function testAiVisionNeverInvokedWhenOcrTextSucceedsInFullFourStrategyCascade(): void
    {
        // Cost-control regression guard. With all four strategies wired in the
        // cascade, OcrText succeeding (priority 70) MUST short-circuit BEFORE
        // AiVision (priority 50) gets a turn. AiVision is wired with an
        // exploding HTTP client so any accidental invocation crashes the test
        // and prevents a "double-billing" regression where the orchestrator
        // continues past a successful strategy.
        $audit = $this->captureAuditLogService();
        $aiVisionExploding = $this->makeAiVisionStrategyWithExplodingClient($audit);
        $orchestrator = new DataExtractionService([
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            $this->makeOcrTextStrategyReplaying('anthropic-success-with-pii.json', $audit),
            $aiVisionExploding,
            new StubExtractionStrategy(),
        ]);

        $document = $this->makeDocument('scan.png', mime: 'image/png', userMode: ExtractionMode::BALANCED);

        $result = $orchestrator->extract($document);

        $this->assertSame(OcrTextExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertCount(1, $audit->loggedCalls, 'Exactly one AI strategy must audit — no double-bill');
        $this->assertSame('ocr_text', $audit->loggedCalls[0]['newData']['strategy']);
    }

    public function testAiVisionRateLimitDeniedInFourStrategyCascadeFallsThroughToFailed(): void
    {
        // Per-tenant cost protection: when a user has exhausted their
        // 50/day vision quota, AiVision must return zero-confidence rather
        // than block the cascade or raise. The orchestrator continues to
        // Stub. End state: status FAILED, no audit (rate-limit denial
        // happens BEFORE the AI call, so AiVision doesn't audit either).
        $audit = $this->captureAuditLogService();

        // OcrText with quality-gate fail (forces cascade past it).
        $shortTextOcr = new class implements OcrServiceInterface {
            public function extractText(string $absolutePath): OcrResult
            {
                return new OcrResult('abc', 0.1, 1);
            }
        };
        $ocrText = new OcrTextExtractionStrategy(
            ocrService: $shortTextOcr,
            llmClient: new AnthropicApiClient(
                httpClient: new MockHttpClient(static function (): MockResponse {
                    throw new \LogicException('OcrText AI must not run when quality gate trips');
                }),
                anthropicApiKey: 'sk-cascade-rate',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $audit,
            extractionAiTextLimiter: $this->noLimitFactory(),
            uploadsDir: self::FIXTURES_DIR . '/../ocr',
            anthropicApiKey: 'sk-cascade-rate',
            logger: new NullLogger(),
        );

        // AiVision with pre-exhausted limiter for the cascade-test default
        // user (no setId() in makeDocument → User::getId() returns null →
        // bucket key is '').
        $exhaustedFactory = new RateLimiterFactory(
            ['id' => 'cascade_vision_exhausted', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 day'],
            new InMemoryStorage(),
        );
        $exhaustedFactory->create('')->consume(1); // pre-drain bucket for null-id user
        $aiVision = new AiVisionExtractionStrategy(
            llmClient: new AnthropicApiClient(
                httpClient: new MockHttpClient(static function (): MockResponse {
                    throw new \LogicException('AiVision LLM must not run when rate limit denied');
                }),
                anthropicApiKey: 'sk-cascade-rate',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $audit,
            extractionAiVisionLimiter: $exhaustedFactory,
            uploadsDir: self::FIXTURES_DIR . '/../ocr',
            anthropicApiKey: 'sk-cascade-rate',
            logger: new NullLogger(),
        );

        $orchestrator = new DataExtractionService([
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            $ocrText,
            $aiVision,
            new StubExtractionStrategy(),
        ]);
        $document = $this->makeDocument('clean-text.png', mime: 'image/png', userMode: ExtractionMode::BALANCED);

        $result = $orchestrator->extract($document);

        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertSame(ExtractionStatus::FAILED, $document->getExtractionStatus());
        $this->assertSame([], $audit->loggedCalls, 'Rate-limit denial must NOT trigger an audit entry');
    }

    public function testFullCascadeWithRealTesseractOnScanPng(): void
    {
        // The only cascade test that actually exercises the REAL Tesseract
        // pipeline (`tests/fixtures/extraction/scan.png` is the page-1 raster
        // of `invoice-realistic.pdf` committed at Pas 2.5.8 W7). All other
        // cascade tests use a fake `OcrServiceInterface` that returns hand-
        // crafted text — that lets them run in milliseconds, but it never
        // proves the orchestrator + OcrTextExtractionStrategy + TesseractOcrService
        // chain composes correctly. A subtle bug in mime detection, in the
        // PiiMasker round-trip, or in how OcrText forwards the OCR text to
        // the prompt could ride invisibly under the fakes; this test catches
        // it. Skipped when tesseract isn't on PATH.
        if (trim((string) shell_exec('which tesseract')) === '') {
            $this->markTestSkipped('Tesseract binary not available; run inside Docker container');
        }

        $audit = $this->captureAuditLogService();
        $orchestrator = new DataExtractionService([
            new PdfParserExtractionStrategy(self::FIXTURES_DIR),
            $this->makeOcrTextStrategyWithRealTesseract($audit),
            new StubExtractionStrategy(),
        ]);

        // BALANCED mode opts the user into AI-backed strategies; PNG mime
        // forces PdfParser to drop and OcrText to take over.
        $document = $this->makeDocument('scan.png', mime: 'image/png', userMode: ExtractionMode::BALANCED);

        $result = $orchestrator->extract($document);

        // OcrText short-circuited the cascade — that's the contract for
        // a clean rasterised PDF. The exact globalConfidence depends on
        // OCR quality on the fixture (typically 0.7-0.9 on
        // invoice-realistic page 1) and on the AI's response to the
        // recovered text; we only require the COMPLETED status here.
        $this->assertSame(OcrTextExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertGreaterThan(0.0, $result->globalConfidence);
        $this->assertSame(ExtractionStatus::COMPLETED, $document->getExtractionStatus());
        // OCR ran end-to-end — rawOcrText is populated (and PiiMasker has
        // already masked any PII before persistence).
        $this->assertNotNull($result->rawOcrText);
        $this->assertStringContainsString('15193236', str_replace(' ', '', $result->rawOcrText), 'Real OCR must recover the creditor CUI from the rasterised invoice');
        // Audit shows exactly one AI extraction event (no double-billing).
        $this->assertCount(1, $audit->loggedCalls);
        $this->assertSame(AuditLogService::CATEGORY_AI_EXTRACTION, $audit->loggedCalls[0]['category']);
    }

    private function makeOcrTextStrategyWithRealTesseract(AuditLogService $audit): OcrTextExtractionStrategy
    {
        // Real Tesseract over a real PNG, real PiiMasker round-trip, real
        // AnthropicApiClient — only the HTTP transport is mocked because we
        // don't burn live API credits in tests. The fixture replay carries
        // creditor/debtor/claim placeholders so the round-trip restoration
        // exercises both CNP and IBAN paths.
        $body = file_get_contents(__DIR__ . '/../../fixtures/llm/anthropic-success-with-pii.json');
        if ($body === false) {
            self::fail('anthropic-success-with-pii.json unreadable in cascade test');
        }
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]));

        return new OcrTextExtractionStrategy(
            ocrService: new TesseractOcrService(new NullLogger(), 'ron+eng'),
            llmClient: new AnthropicApiClient(
                httpClient: $http,
                anthropicApiKey: 'sk-ant-cascade-real-tesseract',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $audit,
            extractionAiTextLimiter: $this->noLimitFactory(),
            // FIXTURES_DIR is `tests/fixtures/extraction/` where scan.png lives.
            uploadsDir: self::FIXTURES_DIR,
            anthropicApiKey: 'sk-ant-cascade-real-tesseract',
            logger: new NullLogger(),
        );
    }
}
