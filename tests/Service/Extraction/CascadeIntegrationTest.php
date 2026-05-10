<?php

namespace App\Tests\Service\Extraction;

use App\DTO\Ocr\OcrResult;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionStatus;
use App\Service\AuditLogService;
use App\Service\Extraction\DataExtractionService;
use App\Service\Extraction\OcrTextExtractionStrategy;
use App\Service\Extraction\PdfParserExtractionStrategy;
use App\Service\Extraction\StubExtractionStrategy;
use App\Service\Llm\AnthropicApiClient;
use App\Service\Ocr\OcrServiceInterface;
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
    ): Document {
        $user = new User();
        $user->setExtractionMode($userMode);

        $case = new LegalCase();
        $case->setUser($user);

        $document = new Document();
        $document->setLegalCase($case);
        $document->setOriginalFilename($filename);
        $document->setStoredFilename($filename);
        $document->setFileSize(1024);
        $document->setMimeType($mime);

        return $document;
    }
}
