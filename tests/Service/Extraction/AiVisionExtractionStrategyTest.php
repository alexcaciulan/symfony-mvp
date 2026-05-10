<?php

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ExtractedDocumentData;
use App\DTO\Llm\LlmResponse;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\LlmFinishReason;
use App\Service\AuditLogService;
use App\Service\Extraction\AiVisionExtractionStrategy;
use App\Service\Llm\LlmClientInterface;
use App\Service\Llm\LlmException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Nivel 1 unit tests — strategy logic in isolation. AI client + audit are
 * fakes; rate limiter is a real Symfony in-memory factory (so the
 * pre-exhaustion ritual stays honest). Test files are written into a unique
 * tmp directory and cleaned up in tearDown — uploadsDir is set to that
 * directory so the strategy reads real files when exercising the file-guard
 * paths.
 *
 * Pair with {@see AiVisionExtractionStrategyIntegrationTest} (Nivel 2 with
 * real AnthropicApiClient + MockHttpClient + fixture replay) and the
 * 2-extension in {@see CascadeIntegrationTest} (Nivel 3 cascade end-to-end).
 */
class AiVisionExtractionStrategyTest extends TestCase
{
    private string $uploadsDir;

    protected function setUp(): void
    {
        // Per-test isolated tmp dir so concurrent runs don't cross-contaminate
        // and tearDown cleanup is always scoped.
        $this->uploadsDir = sys_get_temp_dir() . '/aivision-test-' . uniqid('', true);
        mkdir($this->uploadsDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadsDir)) {
            foreach (glob($this->uploadsDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->uploadsDir);
        }
    }

    private function makeStrategy(
        ?LlmClientInterface $llmClient = null,
        ?AuditLogService $auditLogService = null,
        ?RateLimiterFactory $extractionAiVisionLimiter = null,
        string $apiKey = 'sk-ant-vision-test',
    ): AiVisionExtractionStrategy {
        return new AiVisionExtractionStrategy(
            llmClient: $llmClient ?? $this->fakeLlmClient('{}'),
            auditLogService: $auditLogService ?? $this->fakeAuditLogService(),
            extractionAiVisionLimiter: $extractionAiVisionLimiter ?? $this->noLimitFactory(),
            uploadsDir: $this->uploadsDir,
            anthropicApiKey: $apiKey,
            logger: new NullLogger(),
        );
    }

    private function fakeLlmClient(string $content, ?\Throwable $throw = null): LlmClientInterface
    {
        $client = new class implements LlmClientInterface {
            public ?array $lastMessages = null;
            public ?int $lastMaxTokens = null;
            public ?array $lastDocumentParts = null;
            public string $cannedContent = '{}';
            public ?\Throwable $throw = null;

            public function complete(array $messages, int $maxTokens = 2048, ?array $documentParts = null): LlmResponse
            {
                $this->lastMessages = $messages;
                $this->lastMaxTokens = $maxTokens;
                $this->lastDocumentParts = $documentParts;
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return new LlmResponse(
                    content: $this->cannedContent,
                    tokensIn: 1500,
                    tokensOut: 200,
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

    private function noLimitFactory(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'aivision_no_limit', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
    }

    private function exhaustedLimitFactory(): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => 'aivision_exhausted', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 day'],
            new InMemoryStorage(),
        );
        $factory->create('42')->consume(1); // pre-drain bucket for default user id 42

        return $factory;
    }

    /**
     * Builds a Document with a real on-disk file (or no file if $writeFile=false).
     * Forces a deterministic id (default 99) so the rate-limiter user key is stable.
     */
    private function makeDocument(
        int $id = 99,
        string $mime = 'image/png',
        string $storedFilename = 'doc.png',
        bool $writeFile = true,
        ?int $fileBytes = null,
    ): Document {
        if ($writeFile) {
            // Realistic 4-byte PNG header is enough for file_get_contents to read
            // and base64_encode to produce non-empty output. The actual bytes don't
            // matter — the LLM is a fake here.
            $payload = $fileBytes !== null
                ? str_repeat('x', $fileBytes)
                : "\x89PNG\r\n\x1a\n";
            file_put_contents($this->uploadsDir . '/' . $storedFilename, $payload);
        }

        $user = new User();
        (new \ReflectionClass($user))->getProperty('id')->setValue($user, 42);

        $case = new LegalCase();
        $case->setUser($user);

        $document = new Document();
        $document->setLegalCase($case);
        $document->setOriginalFilename($storedFilename);
        $document->setStoredFilename($storedFilename);
        $document->setFileSize(1024);
        $document->setMimeType($mime);
        (new \ReflectionClass($document))->getProperty('id')->setValue($document, $id);

        return $document;
    }

    // ---------- interface contract ----------

    public function testPriorityIs50AndIsAiBackedReturnsTrue(): void
    {
        $strategy = $this->makeStrategy();

        $this->assertSame(50, $strategy->priority());
        $this->assertSame(AiVisionExtractionStrategy::PRIORITY, $strategy->priority());
        $this->assertTrue($strategy->isAiBacked());
    }

    // ---------- supports() ----------

    public function testSupportsAcceptsImageAndPdfMimeTypes(): void
    {
        $strategy = $this->makeStrategy();

        foreach (['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'] as $mime) {
            $this->assertTrue(
                $strategy->supports($this->makeDocument(mime: $mime, writeFile: false)),
                "Vision must support {$mime}",
            );
        }
    }

    public function testSupportsRejectsUnknownMime(): void
    {
        $strategy = $this->makeStrategy();
        $this->assertFalse(
            $strategy->supports($this->makeDocument(mime: 'application/msword', writeFile: false)),
        );
    }

    public function testSupportsReturnsFalseWhenApiKeyEmpty(): void
    {
        $strategy = $this->makeStrategy(apiKey: '');

        // Even for a perfectly valid PNG mime, an empty apiKey opts the
        // strategy out so the cascade can move on cleanly.
        $this->assertFalse(
            $strategy->supports($this->makeDocument(mime: 'image/png', writeFile: false)),
        );
    }

    // ---------- file guards ----------

    public function testExtractReturnsZeroConfidenceWhenFileMissing(): void
    {
        $strategy = $this->makeStrategy();
        $document = $this->makeDocument(writeFile: false); // no file on disk

        $result = $strategy->extract($document);

        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertSame(AiVisionExtractionStrategy::STRATEGY_KEY, $result->strategy);
    }

    public function testExtractReturnsZeroConfidenceWhenFileTooLarge(): void
    {
        // 6 MB > 5 MB cap — must short-circuit before any AI call.
        $llm = $this->fakeLlmClient('{"creditor":{}}');
        $strategy = $this->makeStrategy(llmClient: $llm);
        $document = $this->makeDocument(fileBytes: 6 * 1024 * 1024);

        $result = $strategy->extract($document);

        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertNull($llm->lastMessages, 'LLM must not be invoked when file exceeds size cap');
    }

    // ---------- vision content block construction ----------

    public function testExtractBuildsImageContentBlockForPng(): void
    {
        $aiContent = json_encode([
            'creditor' => ['name' => 'SC X', 'confidencePerField' => ['name' => 0.9]],
            'globalConfidence' => 0.9,
        ]);
        $llm = $this->fakeLlmClient($aiContent);
        $strategy = $this->makeStrategy(llmClient: $llm);

        $strategy->extract($this->makeDocument(mime: 'image/png', storedFilename: 'doc.png'));

        $this->assertNotNull($llm->lastDocumentParts);
        $this->assertCount(1, $llm->lastDocumentParts);
        $part = $llm->lastDocumentParts[0];
        $this->assertSame('image', $part['type']);
        $this->assertSame('base64', $part['source']['type']);
        $this->assertSame('image/png', $part['source']['media_type']);
        // base64 of "\x89PNG\r\n\x1a\n" — non-empty, valid base64.
        $this->assertNotEmpty($part['source']['data']);
        $this->assertNotFalse(base64_decode($part['source']['data'], true));
    }

    public function testExtractBuildsDocumentContentBlockForPdf(): void
    {
        $aiContent = json_encode([
            'claim' => ['amount' => 1000.0, 'currency' => 'RON'],
            'globalConfidence' => 0.85,
        ]);
        $llm = $this->fakeLlmClient($aiContent);
        $strategy = $this->makeStrategy(llmClient: $llm);

        $strategy->extract($this->makeDocument(mime: 'application/pdf', storedFilename: 'doc.pdf'));

        $part = $llm->lastDocumentParts[0];
        $this->assertSame('document', $part['type']);
        $this->assertSame('application/pdf', $part['source']['media_type']);
    }

    public function testExtractNormalisesImageJpgToImageJpeg(): void
    {
        // `image/jpg` is a common but non-IANA alias; Anthropic accepts only
        // `image/jpeg`. The strategy must rewrite the media_type field
        // accordingly while leaving the document's stored MIME untouched.
        $llm = $this->fakeLlmClient(json_encode(['creditor' => ['name' => 'SC X'], 'globalConfidence' => 0.8]));
        $strategy = $this->makeStrategy(llmClient: $llm);

        $strategy->extract($this->makeDocument(mime: 'image/jpg', storedFilename: 'doc.jpg'));

        $this->assertSame('image/jpeg', $llm->lastDocumentParts[0]['source']['media_type']);
    }

    // ---------- rate limit ----------

    public function testExtractRateLimitedReturnsZeroConfidence(): void
    {
        $llm = $this->fakeLlmClient('{"creditor":{}}');
        $strategy = $this->makeStrategy(
            llmClient: $llm,
            extractionAiVisionLimiter: $this->exhaustedLimitFactory(),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertNull($llm->lastMessages, 'LLM must not be invoked once vision rate limit denied');
    }

    // ---------- LLM error paths ----------

    public function testExtractReturnsZeroConfidenceOnLlmException(): void
    {
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient('{}', new LlmException('Anthropic 503 overloaded')),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(0.0, $result->globalConfidence);
    }

    public function testExtractReturnsZeroConfidenceOnMalformedJson(): void
    {
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient('this is just narrative, no JSON at all'),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(0.0, $result->globalConfidence);
    }

    // ---------- happy path + audit ----------

    public function testExtractReturnsFullDtoOnSuccessfulVisionResponse(): void
    {
        $aiContent = json_encode([
            'creditor' => [
                'personType' => 'PJ',
                'name' => 'SC Foo SRL',
                'cui' => '15193236',
                'isVatPayer' => true,
                'confidencePerField' => ['name' => 0.95, 'cui' => 0.99],
            ],
            'debtor' => [
                'personType' => 'PJ',
                'name' => 'SC Bar SRL',
                'cui' => '14186770',
                'confidencePerField' => ['name' => 0.92],
            ],
            'claim' => [
                'amount' => 6009.50,
                'currency' => 'RON',
                'dueDate' => '2026-06-15',
                'legalGround' => 'CONTRACT_PRESTARI_SERVICII',
                'confidencePerField' => ['amount' => 0.99],
            ],
            'globalConfidence' => 0.91,
        ]);
        $strategy = $this->makeStrategy(llmClient: $this->fakeLlmClient($aiContent));

        $result = $strategy->extract($this->makeDocument());

        $this->assertInstanceOf(ExtractedDocumentData::class, $result);
        $this->assertSame(AiVisionExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertSame(0.91, $result->globalConfidence);
        $this->assertSame('SC Foo SRL', $result->creditor?->name);
        $this->assertSame('SC Bar SRL', $result->debtor?->name);
        $this->assertSame(6009.50, $result->claim?->amount);
        $this->assertEquals(new \DateTimeImmutable('2026-06-15'), $result->claim?->dueDate);
        $this->assertNull($result->rawOcrText, 'Vision must NOT populate rawOcrText — it does not OCR');
    }

    public function testExtractAuditsCallWithAiVisionStrategyAndMetadataIncludingMimeAndSize(): void
    {
        $audit = $this->fakeAuditLogService();
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient(json_encode(['creditor' => ['name' => 'X'], 'globalConfidence' => 0.8])),
            auditLogService: $audit,
        );

        $strategy->extract($this->makeDocument(id: 30, mime: 'image/png'));

        $this->assertCount(1, $audit->loggedCalls);
        $call = $audit->loggedCalls[0];
        $this->assertSame('AI_EXTRACTION_COMPLETED', $call['action']);
        $this->assertSame('Document', $call['entityType']);
        $this->assertSame('30', $call['entityId']);
        $this->assertSame(AuditLogService::CATEGORY_AI_EXTRACTION, $call['category']);
        // Strategy identifier carried forward for cost telemetry per-tier.
        $this->assertSame(AiVisionExtractionStrategy::STRATEGY_KEY, $call['newData']['strategy']);
        // Vision-specific fields beyond OcrText: mimeType + fileSize, so audit
        // analytics can split per content-block kind.
        $this->assertSame('image/png', $call['newData']['mimeType']);
        $this->assertGreaterThan(0, $call['newData']['fileSize']);
        // Tokens + hash, same as OcrText.
        $this->assertSame(1500, $call['newData']['tokensIn']);
        $this->assertSame(200, $call['newData']['tokensOut']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $call['newData']['responseHash']);
        // Audit metadata MUST NOT carry binary content or base64 data.
        $persisted = json_encode($call['newData']);
        $this->assertNotFalse($persisted);
        $this->assertStringNotContainsString('base64', strtolower($persisted));
        $this->assertStringNotContainsString('iVBORw0', $persisted);
    }
}
