<?php

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ExtractedDocumentData;
use App\DTO\Llm\LlmResponse;
use App\DTO\Ocr\OcrResult;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\LlmFinishReason;
use App\Service\AuditLogService;
use App\Service\Extraction\OcrTextExtractionStrategy;
use App\Service\Llm\LlmClientInterface;
use App\Service\Llm\LlmException;
use App\Service\Ocr\OcrException;
use App\Service\Ocr\OcrServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Nivel 1 unit tests — strategy logic in isolation. All collaborators are
 * fakes/spies; no real OCR, no real HTTP. The 3-tier rule
 * (`feedback_test_coverage_3_layers.md` row 2.5.7) classifies 2.5.7 as 🔴
 * — paired with the integration test (Nivel 2) and the cascade test (Nivel 3,
 * extending CascadeIntegrationTest).
 */
class OcrTextExtractionStrategyTest extends TestCase
{
    /**
     * Builds a strategy with controllable collaborators. Every test only sets
     * the fields it actually uses — saves boilerplate.
     */
    private function makeStrategy(
        ?OcrServiceInterface $ocrService = null,
        ?LlmClientInterface $llmClient = null,
        ?AuditLogService $auditLogService = null,
        ?RateLimiterFactory $extractionAiTextLimiter = null,
        string $apiKey = 'sk-ant-test',
    ): OcrTextExtractionStrategy {
        return new OcrTextExtractionStrategy(
            ocrService: $ocrService ?? $this->fakeOcrService(text: '', confidence: 0.0),
            llmClient: $llmClient ?? $this->fakeLlmClient('{}'),
            auditLogService: $auditLogService ?? $this->fakeAuditLogService(),
            extractionAiTextLimiter: $extractionAiTextLimiter ?? $this->noLimitFactory(),
            uploadsDir: '/tmp/uploads-not-touched-in-unit-tests',
            anthropicApiKey: $apiKey,
            logger: new NullLogger(),
        );
    }

    private function fakeOcrService(string $text, float $confidence, int $pageCount = 1, ?\Throwable $throw = null): OcrServiceInterface
    {
        return new class($text, $confidence, $pageCount, $throw) implements OcrServiceInterface {
            public function __construct(
                private string $text,
                private float $confidence,
                private int $pageCount,
                private ?\Throwable $throw,
            ) {}

            public function extractText(string $absolutePath): OcrResult
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return new OcrResult($this->text, $this->confidence, $this->pageCount);
            }
        };
    }

    /**
     * Fake LlmClient that captures the last messages array and returns either a
     * canned LlmResponse or throws. Used to spy on the prompt sent to the AI.
     * Return type is the bare interface — call sites access the public spy
     * properties (`lastMessages`, `lastMaxTokens`) directly on the anonymous
     * class instance, which PHP allows but static analysers may flag.
     */
    private function fakeLlmClient(string $content, ?\Throwable $throw = null): LlmClientInterface
    {
        $client = new class implements LlmClientInterface {
            public ?array $lastMessages = null;
            public ?int $lastMaxTokens = null;
            public string $cannedContent = '{}';
            public ?\Throwable $throw = null;

            public function complete(array $messages, int $maxTokens = 2048, ?array $documentParts = null): LlmResponse
            {
                $this->lastMessages = $messages;
                $this->lastMaxTokens = $maxTokens;
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return new LlmResponse(
                    content: $this->cannedContent,
                    tokensIn: 100,
                    tokensOut: 50,
                    finishReason: LlmFinishReason::COMPLETED,
                );
            }
        };
        $client->cannedContent = $content;
        $client->throw = $throw;

        return $client;
    }

    private function fakeAuditLogService(): AuditLogService
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
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'newData' => $newData,
                    'category' => $category,
                ];

                return new \App\Entity\AuditLog();
            }
        };
    }

    /**
     * RateLimiterFactory that always allows. The `when@test` config in
     * rate_limiter.yaml does the same in the real container — we replicate it
     * here so unit tests don't depend on the global filesystem cache state.
     */
    private function noLimitFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test_no_limit', 'policy' => 'no_limit'],
            new \Symfony\Component\RateLimiter\Storage\InMemoryStorage(),
        );
    }

    private function exhaustedLimitFactory(): RateLimiterFactory
    {
        // limit=1, pre-exhausted: build the factory, drain its single token, then
        // hand it to the strategy so the next ->consume(1) is denied. Symfony's
        // FixedWindowLimiter rejects limit=0 at construction time, so a
        // pre-exhaustion ritual is the only way to simulate "denied" with a real
        // factory (no need for a custom mock class).
        $factory = new RateLimiterFactory(
            ['id' => 'test_exhausted', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 day'],
            new \Symfony\Component\RateLimiter\Storage\InMemoryStorage(),
        );
        // Drain the bucket for the user-id used in the test (`42`).
        $factory->create('42')->consume(1);

        return $factory;
    }

    private function makeDocument(int $id, string $mime, string $storedFilename = 'doc.pdf'): Document
    {
        // Force an id so the rate-limiter key is deterministic. ReflectionProperty
        // can write private props directly in PHP 8.1+ — no setAccessible() needed
        // (it's a no-op on properties since 8.1 and formally deprecated in 8.5).
        $user = new User();
        (new \ReflectionClass($user))->getProperty('id')->setValue($user, 42);

        $legalCase = new LegalCase();
        $legalCase->setUser($user);

        $document = new Document();
        $document->setLegalCase($legalCase);
        $document->setOriginalFilename($storedFilename);
        $document->setStoredFilename($storedFilename);
        $document->setFileSize(1024);
        $document->setMimeType($mime);
        (new \ReflectionClass($document))->getProperty('id')->setValue($document, $id);

        return $document;
    }

    // ---------- interface contract ----------

    public function testPriorityIs70AndIsAiBackedReturnsTrue(): void
    {
        $strategy = $this->makeStrategy();

        $this->assertSame(70, $strategy->priority());
        $this->assertSame(OcrTextExtractionStrategy::PRIORITY, $strategy->priority());
        $this->assertTrue($strategy->isAiBacked());
    }

    // ---------- supports() ----------

    public function testSupportsReturnsTrueForJpegPng(): void
    {
        $strategy = $this->makeStrategy();

        $this->assertTrue($strategy->supports($this->makeDocument(1, 'image/jpeg')));
        $this->assertTrue($strategy->supports($this->makeDocument(2, 'image/png')));
    }

    public function testSupportsReturnsFalseForUnknownMime(): void
    {
        $strategy = $this->makeStrategy();
        $document = $this->makeDocument(3, 'application/msword');

        $this->assertFalse($strategy->supports($document));
    }

    // ---------- extract() — OCR failures ----------

    public function testExtractReturnsZeroConfidenceWhenOcrConfidenceTooLow(): void
    {
        $strategy = $this->makeStrategy(
            ocrService: $this->fakeOcrService(text: str_repeat('x', 500), confidence: 0.3),
        );
        $document = $this->makeDocument(10, 'image/png');

        $result = $strategy->extract($document);

        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertSame(OcrTextExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertSame(10, $result->sourceDocumentId);
    }

    public function testExtractReturnsZeroConfidenceWhenOcrTextTooShort(): void
    {
        $strategy = $this->makeStrategy(
            ocrService: $this->fakeOcrService(text: 'short', confidence: 0.95),
        );
        $document = $this->makeDocument(11, 'image/png');

        $result = $strategy->extract($document);

        $this->assertSame(0.0, $result->globalConfidence);
    }

    public function testExtractReturnsZeroConfidenceWhenOcrThrows(): void
    {
        $strategy = $this->makeStrategy(
            ocrService: $this->fakeOcrService(text: '', confidence: 0.0, throw: new OcrException('Tesseract crashed')),
        );

        $result = $strategy->extract($this->makeDocument(12, 'image/png'));

        $this->assertSame(0.0, $result->globalConfidence);
    }

    // ---------- extract() — D1: apiKey absent ----------

    public function testExtractSkipsAndReturnsZeroConfidenceWhenApiKeyEmpty(): void
    {
        $llm = $this->fakeLlmClient('{"creditor":{}}');
        $strategy = $this->makeStrategy(
            ocrService: $this->fakeOcrService(text: str_repeat('text body ', 50), confidence: 0.9),
            llmClient: $llm,
            apiKey: '', // operational misconfiguration — D1 says skip.
        );

        $result = $strategy->extract($this->makeDocument(13, 'image/png'));

        $this->assertSame(0.0, $result->globalConfidence);
        // LLM must NOT have been called when apiKey is empty.
        $this->assertNull($llm->lastMessages);
    }

    // ---------- extract() — happy path + PII masking ----------

    public function testExtractMasksCnpAndIbanInPromptToAi(): void
    {
        // OCR text contains a real-checksum CNP + an IBAN with valid Romanian shape.
        $cnp = '1980715221232'; // Pas 2.5.4 fixture, OUG 97/2005 valid.
        $iban = 'RO49AAAA1B31007593840000';
        $ocrText = "Document juridic cu date personale.\n"
            . "Debitor: persoană fizică, CNP {$cnp}, domiciliu...\n"
            . "Cont creditor: {$iban} deschis la BCR.\n"
            . str_repeat('Articol contractual cu detaliu suficient pentru lungime minimă. ', 5);

        $llm = $this->fakeLlmClient(
            content: '{"creditor":{"name":"SC Foo SRL"},"debtor":{"name":"Popescu Maria"},"claim":{"amount":1000}}',
        );
        $strategy = $this->makeStrategy(
            ocrService: $this->fakeOcrService(text: $ocrText, confidence: 0.92),
            llmClient: $llm,
        );

        $strategy->extract($this->makeDocument(20, 'image/png'));

        $this->assertNotNull($llm->lastMessages, 'LLM must be invoked when all preconditions hold');

        $userMessage = $llm->lastMessages[1]['content'];
        $this->assertIsString($userMessage);
        // Original PII is replaced before crossing the network boundary.
        $this->assertStringNotContainsString($cnp, $userMessage);
        $this->assertStringNotContainsString($iban, $userMessage);
        // Round-trip placeholders must appear in the prompt so the AI can echo them back.
        $this->assertStringContainsString('***-***-1232', $userMessage);
        $this->assertStringContainsString('IBAN_PLACEHOLDER_001', $userMessage);
    }

    public function testExtractRestoresCnpAndIbanInExtractionResult(): void
    {
        $cnp = '1980715221232';
        $iban = 'RO49AAAA1B31007593840000';
        $ocrText = "Date OCR\nCNP {$cnp}\nCont {$iban}\n" . str_repeat('Lipsă conținut suplimentar pentru atingerea pragului de lungime. ', 5);

        // The fake AI response uses the placeholders verbatim — this is what a
        // well-behaved Claude does when the prompt instructs it to keep them.
        $aiContent = json_encode([
            'creditor' => [
                'name' => 'SC Foo SRL',
                'iban' => 'IBAN_PLACEHOLDER_001',
                'confidencePerField' => ['name' => 0.95, 'iban' => 0.92],
            ],
            'debtor' => [
                'name' => 'Popescu Maria',
                'personalId' => '***-***-1232',
                'confidencePerField' => ['personalId' => 0.99],
            ],
            'claim' => ['amount' => 5000.0, 'currency' => 'RON'],
            'globalConfidence' => 0.9,
        ]);

        $strategy = $this->makeStrategy(
            ocrService: $this->fakeOcrService(text: $ocrText, confidence: 0.92),
            llmClient: $this->fakeLlmClient(content: $aiContent),
        );

        $result = $strategy->extract($this->makeDocument(21, 'image/png'));

        $this->assertInstanceOf(ExtractedDocumentData::class, $result);
        $this->assertSame(0.9, $result->globalConfidence);
        $this->assertSame($iban, $result->creditor?->iban, 'IBAN should be restored from placeholder');
        $this->assertSame($cnp, $result->debtor?->personalId, 'CNP should be restored from placeholder');

        // rawOcrText is persisted as Document.extractedData JSON — GDPR data minimisation
        // requires CNP and IBAN masked at the persistence boundary. Structured fields
        // (creditor/debtor/claim) still carry the restored values for the wizard.
        $this->assertNotNull($result->rawOcrText);
        $this->assertStringNotContainsString($cnp, $result->rawOcrText);
        $this->assertStringNotContainsString($iban, $result->rawOcrText);
        $this->assertStringContainsString('***-***-1232', $result->rawOcrText);
        $this->assertStringContainsString('RO**REDACTED**', $result->rawOcrText);
    }

    public function testExtractAuditsCallWithAiExtractionCategoryAndMaskedMetadata(): void
    {
        $audit = $this->fakeAuditLogService();
        $aiContent = json_encode([
            'creditor' => ['name' => 'SC Foo SRL', 'confidencePerField' => ['name' => 0.95]],
            'globalConfidence' => 0.9,
        ]);
        $strategy = $this->makeStrategy(
            ocrService: $this->fakeOcrService(text: str_repeat('payload data ', 50), confidence: 0.92),
            llmClient: $this->fakeLlmClient(content: $aiContent),
            auditLogService: $audit,
        );

        $strategy->extract($this->makeDocument(30, 'image/png'));

        $this->assertCount(1, $audit->loggedCalls);
        $call = $audit->loggedCalls[0];
        $this->assertSame('AI_EXTRACTION_COMPLETED', $call['action']);
        $this->assertSame('Document', $call['entityType']);
        $this->assertSame('30', $call['entityId']);
        $this->assertSame(AuditLogService::CATEGORY_AI_EXTRACTION, $call['category']);
        // Metadata persisted does NOT contain prompt content or raw text.
        $this->assertSame(OcrTextExtractionStrategy::STRATEGY_KEY, $call['newData']['strategy']);
        $this->assertSame(100, $call['newData']['tokensIn']);
        $this->assertSame(50, $call['newData']['tokensOut']);
        // LlmFinishReason is the neutral-provider enum; 'end_turn' (Anthropic)
        // is mapped to COMPLETED inside AnthropicApiClient::mapStopReason.
        $this->assertSame('COMPLETED', $call['newData']['finishReason']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $call['newData']['responseHash']);
        $this->assertArrayNotHasKey('prompt', $call['newData']);
        $this->assertArrayNotHasKey('content', $call['newData']);
    }

    // ---------- extract() — error paths ----------

    public function testExtractReturnsZeroConfidenceOnLlmException(): void
    {
        $strategy = $this->makeStrategy(
            ocrService: $this->fakeOcrService(text: str_repeat('payload ', 50), confidence: 0.92),
            llmClient: $this->fakeLlmClient(content: '{}', throw: new LlmException('Anthropic 503 overloaded')),
        );

        $result = $strategy->extract($this->makeDocument(40, 'image/png'));

        $this->assertSame(0.0, $result->globalConfidence);
    }

    public function testExtractReturnsZeroConfidenceOnMalformedJson(): void
    {
        $strategy = $this->makeStrategy(
            ocrService: $this->fakeOcrService(text: str_repeat('payload ', 50), confidence: 0.92),
            llmClient: $this->fakeLlmClient(content: 'this is just narrative, no JSON at all'),
        );

        $result = $strategy->extract($this->makeDocument(41, 'image/png'));

        $this->assertSame(0.0, $result->globalConfidence);
    }

    public function testExtractRateLimitedReturnsZeroConfidence(): void
    {
        $llm = $this->fakeLlmClient(content: '{"creditor":{}}');
        $strategy = $this->makeStrategy(
            ocrService: $this->fakeOcrService(text: str_repeat('payload ', 50), confidence: 0.92),
            llmClient: $llm,
            extractionAiTextLimiter: $this->exhaustedLimitFactory(),
        );

        $result = $strategy->extract($this->makeDocument(42, 'image/png'));

        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertNull($llm->lastMessages, 'LLM must not be invoked once rate limit denied');
    }

    public function testExtractAcceptsAiResponseWrappedInMarkdownFence(): void
    {
        $aiContent = "```json\n" . json_encode([
            'creditor' => ['name' => 'SC X', 'confidencePerField' => ['name' => 0.9]],
            'globalConfidence' => 0.88,
        ]) . "\n```";

        $strategy = $this->makeStrategy(
            ocrService: $this->fakeOcrService(text: str_repeat('payload ', 50), confidence: 0.92),
            llmClient: $this->fakeLlmClient(content: $aiContent),
        );

        $result = $strategy->extract($this->makeDocument(50, 'image/png'));

        $this->assertSame(0.88, $result->globalConfidence);
        $this->assertSame('SC X', $result->creditor?->name);
    }
}
