<?php

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ExtractedDocumentData;
use App\DTO\Llm\LlmResponse;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ExtractionFailureReason;
use App\Enum\LlmFinishReason;
use App\Service\AuditLogService;
use App\Service\Extraction\AiVisionExtractionStrategy;
use App\Service\Llm\LlmClientInterface;
use App\Service\Llm\LlmException;
use App\Tests\Support\ExtractionPrompts;
use App\Enum\DocumentType;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
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
        ?LoggerInterface $logger = null,
        ?RateLimiterFactory $extractionAiVisionBurstLimiter = null,
    ): AiVisionExtractionStrategy {
        return new AiVisionExtractionStrategy(
            llmClient: $llmClient ?? $this->fakeLlmClient('{}'),
            promptRegistry: ExtractionPrompts::registry(),
            auditLogService: $auditLogService ?? $this->fakeAuditLogService(),
            extractionAiVisionLimiter: $extractionAiVisionLimiter ?? $this->noLimitFactory(),
            extractionAiVisionBurstLimiter: $extractionAiVisionBurstLimiter ?? $this->noLimitFactory(),
            uploadsDir: $this->uploadsDir,
            anthropicApiKey: $apiKey,
            logger: $logger ?? new NullLogger(),
        );
    }

    /**
     * Returns a PSR-3 logger that captures every log call into a public
     * `$records` array. Used to assert the GDPR transparency event
     * (`extraction.ai_vision.binary_sent_unmasked`) carries the required
     * audit metadata (documentId + userId + mimeType + fileSize).
     */
    private function capturingLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            /** @var array<int, array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    private function fakeLlmClient(
        string $content,
        ?\Throwable $throw = null,
        LlmFinishReason $finishReason = LlmFinishReason::COMPLETED,
    ): LlmClientInterface {
        $client = new class implements LlmClientInterface {
            public ?array $lastMessages = null;
            public ?int $lastMaxTokens = null;
            public ?array $lastDocumentParts = null;
            public ?bool $lastCacheSystemPrompt = null;
            public ?array $lastOutputSchema = null;
            public string $cannedContent = '{}';
            public ?\Throwable $throw = null;
            public LlmFinishReason $cannedFinishReason = LlmFinishReason::COMPLETED;

            public function complete(array $messages, int $maxTokens = 2048, ?array $documentParts = null, bool $cacheSystemPrompt = false, ?array $outputSchema = null): LlmResponse
            {
                $this->lastMessages = $messages;
                $this->lastMaxTokens = $maxTokens;
                $this->lastDocumentParts = $documentParts;
                $this->lastCacheSystemPrompt = $cacheSystemPrompt;
                $this->lastOutputSchema = $outputSchema;
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return new LlmResponse(
                    content: $this->cannedContent,
                    tokensIn: 1500,
                    tokensOut: 200,
                    finishReason: $this->cannedFinishReason,
                );
            }
        };
        $client->cannedContent = $content;
        $client->throw = $throw;
        $client->cannedFinishReason = $finishReason;

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
        DocumentType $documentType = DocumentType::ALT_DOCUMENT,
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
        // What the wizard stores when the lawyer did not declare a type.
        $document->setDocumentType($documentType);
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
        // 6 MB exceeds the image cap, so it must short-circuit before any AI call.
        $llm = $this->fakeLlmClient('{"creditor":{}}');
        $strategy = $this->makeStrategy(llmClient: $llm);
        $document = $this->makeDocument(fileBytes: 6 * 1024 * 1024);

        $result = $strategy->extract($document);

        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertSame(ExtractionFailureReason::FILE_TOO_LARGE, $result->failureReason);
        $this->assertNull($llm->lastMessages, 'LLM must not be invoked when file exceeds size cap');
    }

    /**
     * A 5 MB PDF is well within what a `document` block accepts; under the old
     * shared 3.7 MB image cap it was rejected without ever reaching the model.
     */
    public function testExtractAcceptsFiveMegabytePdfThatTheImageCapWouldReject(): void
    {
        $llm = $this->fakeLlmClient(json_encode([
            'creditor' => ['name' => 'SC X', 'confidencePerField' => ['name' => 0.9]],
        ]));
        $strategy = $this->makeStrategy(llmClient: $llm);
        $document = $this->makeDocument(
            mime: 'application/pdf',
            storedFilename: 'big.pdf',
            fileBytes: 5 * 1000 * 1000,
        );

        $result = $strategy->extract($document);

        $this->assertNotNull($llm->lastDocumentParts, 'A 5 MB PDF must reach the model');
        $this->assertSame('document', $llm->lastDocumentParts[0]['type']);
        $this->assertNull($result->failureReason);
    }

    public function testExtractRejectsPdfAboveThePdfCap(): void
    {
        $llm = $this->fakeLlmClient('{"creditor":{}}');
        $strategy = $this->makeStrategy(llmClient: $llm);
        $document = $this->makeDocument(
            mime: 'application/pdf',
            storedFilename: 'huge.pdf',
            fileBytes: 11 * 1000 * 1000,
        );

        $result = $strategy->extract($document);

        $this->assertSame(ExtractionFailureReason::FILE_TOO_LARGE, $result->failureReason);
        $this->assertNull($llm->lastMessages);
    }

    /**
     * A truncated response is a budget problem, not corrupt output. Before the
     * finishReason guard both ended in the same place, because a cut-off JSON
     * body fails to parse exactly like a malformed one.
     */
    public function testExtractReportsTruncationSeparatelyFromMalformedJson(): void
    {
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient(
                '{"creditor": {"name": "SC Trunc',
                finishReason: LlmFinishReason::MAX_TOKENS,
            ),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertSame(ExtractionFailureReason::RESPONSE_TRUNCATED, $result->failureReason);
    }

    public function testExtractReportsMalformedWhenTheModelFinishedNormally(): void
    {
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient('this is just narrative, no JSON at all'),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(ExtractionFailureReason::RESPONSE_MALFORMED, $result->failureReason);
    }

    /**
     * The failure that cost two of three identically shaped invoices on a live
     * run. The generic prompt asks for a classification and an extraction in one
     * answer, and a model without constrained decoding sometimes answers with
     * one object per task, each in its own fence. Taking everything between the
     * first brace and the last spanned the gap between the two objects and
     * decoded to nothing, so a perfectly good reading was reported as garbage
     * and the lawyer was offered no way forward but to correct the type by hand.
     */
    public function testExtractRecoversAnAnswerSplitAcrossTwoJsonObjects(): void
    {
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient(
                "```json\n{\"classification\":{\"type\":\"factura\",\"confidence\":0.93}}\n```\n\n"
                . "```json\n{\"creditor\":{\"name\":\"Alfa SRL\",\"confidencePerField\":{\"name\":0.95}},"
                . "\"claim\":{\"amount\":1200,\"confidencePerField\":{\"amount\":0.95}}}\n```",
            ),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertNull($result->failureReason);
        $this->assertSame(DocumentType::FACTURA, $result->classification?->type);
        $this->assertSame('Alfa SRL', $result->creditor?->name);
        $this->assertSame(1200.0, $result->claim?->amount);
    }

    /**
     * The same failure in its other shape: the object is intact and the model
     * added a closing remark that happens to contain braces.
     */
    public function testExtractRecoversAnAnswerFollowedByProseContainingBraces(): void
    {
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient(
                '{"claim":{"amount":1200,"confidencePerField":{"amount":0.95}}}'
                . "\n\nNota: suma {TVA inclus} este preluata din totalul facturii.",
            ),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertNull($result->failureReason);
        $this->assertSame(1200.0, $result->claim?->amount);
    }

    /**
     * A brace inside a description must not end the object early.
     */
    public function testExtractKeepsBracesThatAreInsideStringValues(): void
    {
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient(
                'Am analizat documentul.'
                . "\n\n" . '{"claim":{"description":"pozitia {A} din anexa","amount":1200,'
                . '"confidencePerField":{"amount":0.95,"description":0.9}}}',
            ),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertNull($result->failureReason);
        $this->assertSame('pozitia {A} din anexa', $result->claim?->description);
    }

    /**
     * An answer nothing can be recovered from stays a failure, and one the
     * lawyer is offered a retry on: with unconstrained decoding the same file
     * re-read usually comes back fine, which is exactly why two of three
     * identical invoices failed and the third did not.
     */
    public function testAMalformedResponseIsOfferedARetry(): void
    {
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient('this is just narrative, no JSON at all'),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(ExtractionFailureReason::RESPONSE_MALFORMED, $result->failureReason);
        $this->assertTrue($result->failureReason->isTransient());
    }

    public function testExtractReportsApiUnavailableWhenTheProviderIsDown(): void
    {
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient('{}', LlmException::transient('Anthropic 503 overloaded', 503)),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(ExtractionFailureReason::API_UNAVAILABLE, $result->failureReason);
        $this->assertTrue($result->failureReason->isTransient());
    }

    /**
     * A rejected request is rejected identically on every replay, so it must not
     * be reported as transient: each retry would spend another slice of the
     * daily and per-minute budgets for the same answer.
     */
    public function testExtractReportsProviderRejectedOnAPermanentClientError(): void
    {
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient('{}', new LlmException(
                'Anthropic API returned HTTP 400 (type=invalid_request_error)',
                transient: false,
                statusCode: 400,
            )),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(ExtractionFailureReason::PROVIDER_REJECTED, $result->failureReason);
        $this->assertFalse($result->failureReason->isTransient());
    }

    /**
     * A failure that never reached an HTTP status is a response the client could
     * not make sense of, which is what RESPONSE_MALFORMED already means.
     */
    public function testExtractReportsMalformedWhenTheClientFailedWithoutAStatus(): void
    {
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient('{}', new LlmException('Anthropic response was not valid JSON')),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(ExtractionFailureReason::RESPONSE_MALFORMED, $result->failureReason);
    }

    public function testExtractReportsFileUnreadableWhenTheFileIsMissing(): void
    {
        $strategy = $this->makeStrategy();

        $result = $strategy->extract($this->makeDocument(writeFile: false));

        $this->assertSame(ExtractionFailureReason::FILE_UNREADABLE, $result->failureReason);
    }

    public function testExtractReportsRateLimitWhenTheDailyBudgetIsSpent(): void
    {
        $llm = $this->fakeLlmClient('{"creditor":{}}');
        $strategy = $this->makeStrategy(
            llmClient: $llm,
            extractionAiVisionLimiter: $this->exhaustedLimitFactory(),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(ExtractionFailureReason::RATE_LIMIT_EXCEEDED, $result->failureReason);
        $this->assertNull($llm->lastMessages);
    }

    public function testExtractReportsRateLimitWhenTheBurstWindowIsSpent(): void
    {
        $llm = $this->fakeLlmClient('{"creditor":{}}');
        $strategy = $this->makeStrategy(
            llmClient: $llm,
            extractionAiVisionBurstLimiter: $this->exhaustedLimitFactory(),
        );

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(ExtractionFailureReason::RATE_LIMIT_EXCEEDED, $result->failureReason);
        $this->assertNull($llm->lastMessages, 'The burst window must stop the call before the model is reached');
    }

    /**
     * The single most direct statement of the split cap: the same number of
     * bytes is too big as an image (the API caps image blocks near 5 MB base64)
     * and perfectly fine as a PDF. A regression that merges the two constants
     * back into one shows up here whichever way it is merged.
     */
    public function testSameByteCountIsRejectedAsImageAndAcceptedAsPdf(): void
    {
        $bytes = 4 * 1000 * 1000;

        $imageLlm = $this->fakeLlmClient('{"creditor":{}}');
        $imageResult = $this->makeStrategy(llmClient: $imageLlm)->extract(
            $this->makeDocument(mime: 'image/png', storedFilename: 'big.png', fileBytes: $bytes),
        );

        $pdfLlm = $this->fakeLlmClient(json_encode([
            'creditor' => ['name' => 'SC X', 'confidencePerField' => ['name' => 0.9]],
        ]));
        $pdfResult = $this->makeStrategy(llmClient: $pdfLlm)->extract(
            $this->makeDocument(mime: 'application/pdf', storedFilename: 'big.pdf', fileBytes: $bytes),
        );

        $this->assertSame(ExtractionFailureReason::FILE_TOO_LARGE, $imageResult->failureReason);
        $this->assertNull($imageLlm->lastMessages);

        $this->assertNull($pdfResult->failureReason);
        $this->assertNotNull($pdfLlm->lastDocumentParts);
    }

    /**
     * The limiters sit after the file guards precisely so a rejected upload
     * costs the lawyer nothing. If they ever move above the guards, an
     * onboarding batch of oversized scans silently burns the daily budget.
     */
    public function testAFileRejectedForSizeDoesNotSpendRateLimitBudget(): void
    {
        $daily = new RateLimiterFactory(
            ['id' => 'aivision_budget_probe', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 day'],
            new InMemoryStorage(),
        );
        $burst = new RateLimiterFactory(
            ['id' => 'aivision_burst_probe', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient('{"creditor":{}}'),
            extractionAiVisionLimiter: $daily,
            extractionAiVisionBurstLimiter: $burst,
        );

        $strategy->extract($this->makeDocument(fileBytes: 6 * 1024 * 1024));

        // User id 42 is what makeDocument() pins on the owner.
        $this->assertTrue($daily->create('42')->consume(1)->isAccepted());
        $this->assertTrue($burst->create('42')->consume(1)->isAccepted());
    }

    /**
     * supports() should have filtered this out already, so reaching extract()
     * with an unreadable mime means the two disagree. Failing closed with a
     * distinct reason keeps that visible instead of blaming the model.
     */
    public function testExtractReportsUnsupportedMimeWhenNoContentBlockCanBeBuilt(): void
    {
        $llm = $this->fakeLlmClient('{"creditor":{}}');
        $strategy = $this->makeStrategy(llmClient: $llm);

        $result = $strategy->extract($this->makeDocument(
            mime: 'application/msword',
            storedFilename: 'contract.doc',
        ));

        $this->assertSame(ExtractionFailureReason::UNSUPPORTED_MIME, $result->failureReason);
        $this->assertNull($llm->lastMessages);
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
            llmClient: $this->fakeLlmClient('{}', LlmException::transient('Anthropic 503 overloaded', 503)),
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
        // globalConfidence is coverage-weighted (sum of per-field / 25 expected
        // fields). AI-returned `globalConfidence: 0.91` is intentionally ignored.
        // Real assertion below: the DTO structure was populated.
        $this->assertGreaterThan(0.0, $result->globalConfidence);
        $this->assertSame('SC Foo SRL', $result->creditor?->name);
        $this->assertSame('SC Bar SRL', $result->primaryDebtor()?->name);
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

    public function testExtractMapsNewInvoiceContractPenaltyAndBankFields(): void
    {
        $aiContent = json_encode([
            'creditor' => [
                'name' => 'SC Foo SRL',
                'iban' => 'RO49AAAA1B31007593840000',
                'bankName' => 'Banca Transilvania',
                'confidencePerField' => ['name' => 0.95, 'bankName' => 0.9],
            ],
            'claim' => [
                'amount' => 12000.0,
                'currency' => 'RON',
                'dueDate' => '2026-02-01',
                'invoiceNumber' => 'MJ 2026-00042',
                'invoiceDate' => '2026-01-10',
                'contractNumber' => '45/2025',
                'contractDate' => '2025-12-01',
                'contractReference' => 'contract de prestări servicii',
                'penaltyType' => 'CONTRACTUAL',
                'contractualPenaltyRate' => 0.1,
                'confidencePerField' => ['amount' => 0.99, 'invoiceNumber' => 0.95],
            ],
            'globalConfidence' => 0.9,
        ]);
        $strategy = $this->makeStrategy(llmClient: $this->fakeLlmClient($aiContent));

        $result = $strategy->extract($this->makeDocument(id: 70));

        $this->assertSame('Banca Transilvania', $result->creditor?->bankName);
        $this->assertSame('MJ 2026-00042', $result->claim?->invoiceNumber);
        $this->assertSame('2026-01-10', $result->claim?->invoiceDate?->format('Y-m-d'));
        $this->assertSame('45/2025', $result->claim?->contractNumber);
        $this->assertSame('2025-12-01', $result->claim?->contractDate?->format('Y-m-d'));
        $this->assertSame('contract de prestări servicii', $result->claim?->contractReference);
        $this->assertSame(\App\Enum\PenaltyType::CONTRACTUAL, $result->claim?->penaltyType);
        $this->assertSame(0.1, $result->claim?->contractualPenaltyRate);

        // Round-trip through the persisted JSON shape: the new keys must survive
        // toArray() or they never reach the prefill read-back.
        $array = $result->toArray();
        $this->assertSame('Banca Transilvania', $array['creditor']['bankName']);
        $this->assertSame('MJ 2026-00042', $array['claim']['invoiceNumber']);
        $this->assertSame('CONTRACTUAL', $array['claim']['penaltyType']);
        $this->assertSame(0.1, $array['claim']['contractualPenaltyRate']);
        $this->assertStringStartsWith('2026-01-10', $array['claim']['invoiceDate']);
    }

    public function testInvalidPenaltyTypeFromAiBecomesNull(): void
    {
        // Mirror of testInvalidLegalGroundCategoryFromAiBecomesNull: an enum
        // value outside PenaltyType degrades to null rather than raising.
        $aiContent = json_encode([
            'claim' => [
                'amount' => 5000.0,
                'penaltyType' => 'UNKNOWN_PENALTY',
                'contractualPenaltyRate' => 0.1,
                'confidencePerField' => ['amount' => 0.95],
            ],
            'globalConfidence' => 0.7,
        ]);
        $strategy = $this->makeStrategy(llmClient: $this->fakeLlmClient($aiContent));

        $result = $strategy->extract($this->makeDocument(id: 72));

        $this->assertNotNull($result->claim);
        $this->assertNull($result->claim->penaltyType, 'Unknown enum value must NOT raise; degrades to null');
        $this->assertSame(5000.0, $result->claim->amount);
    }

    public function testExtractLeavesPenaltyNullWhenAbsentFromVisionResponse(): void
    {
        // No penalty clause in the document → AI returns null/omits the fields.
        // The strategy must NOT invent a penalty type; the wizard default applies.
        $aiContent = json_encode([
            'claim' => [
                'amount' => 3000.0,
                'currency' => 'RON',
                'confidencePerField' => ['amount' => 0.95],
            ],
            'globalConfidence' => 0.6,
        ]);
        $strategy = $this->makeStrategy(llmClient: $this->fakeLlmClient($aiContent));

        $result = $strategy->extract($this->makeDocument(id: 71));

        $this->assertNull($result->claim?->penaltyType);
        $this->assertNull($result->claim?->contractualPenaltyRate);
    }

    // ---------- AI response edge cases (parser robustness) ----------

    public function testInvalidLegalGroundCategoryFromAiBecomesNull(): void
    {
        $aiContent = json_encode([
            'claim' => [
                'amount' => 5000.0,
                'currency' => 'RON',
                'legalGround' => 'IMPRUMUT_CAMATARESC', // not in LegalGroundCategory enum
                'confidencePerField' => ['amount' => 0.95],
            ],
            'globalConfidence' => 0.7,
        ]);
        $strategy = $this->makeStrategy(llmClient: $this->fakeLlmClient($aiContent));

        $result = $strategy->extract($this->makeDocument(id: 80));

        $this->assertNotNull($result->claim);
        $this->assertNull($result->claim->legalGround, 'Unknown enum value must NOT raise; degrades to null');
        $this->assertSame(5000.0, $result->claim->amount, 'Other claim fields survive an invalid legalGround');
    }

    public function testConfidenceValuesOutsideZeroOneRangeAreClamped(): void
    {
        $aiContent = json_encode([
            'creditor' => [
                'name' => 'SC Foo',
                // Both fields carry a value: a score for a field left empty is
                // dropped as non-coverage, which would hide the clamping.
                'cui' => '12345678',
                'confidencePerField' => ['name' => 1.5, 'cui' => -0.3],
            ],
            'globalConfidence' => 1.5, // out-of-range; must clamp to 1.0
        ]);
        $strategy = $this->makeStrategy(llmClient: $this->fakeLlmClient($aiContent));

        $result = $strategy->extract($this->makeDocument(id: 81));

        // Per-field clamping verified by the two asserts below. globalConfidence
        // is coverage-weighted now and stays in [0, 1] by construction of
        // CoverageConfidenceCalculator.
        $this->assertLessThanOrEqual(1.0, $result->globalConfidence);
        $this->assertGreaterThanOrEqual(0.0, $result->globalConfidence);
        $this->assertSame(1.0, $result->creditor?->confidencePerField['name']);
        $this->assertSame(0.0, $result->creditor?->confidencePerField['cui']);
    }

    public function testMalformedDueDateBecomesNullClaim(): void
    {
        // Vision sometimes emits dates with `/` separator or Romanian format.
        // createFromFormat('!Y-m-d') returns false → coercion to null without
        // leaking a garbage DateTimeImmutable into the wizard.
        $aiContent = json_encode([
            'claim' => [
                'amount' => 5000.0,
                'currency' => 'RON',
                'dueDate' => '15/06/2026',
            ],
            'globalConfidence' => 0.7,
        ]);
        $strategy = $this->makeStrategy(llmClient: $this->fakeLlmClient($aiContent));

        $result = $strategy->extract($this->makeDocument(id: 82));

        $this->assertNotNull($result->claim);
        $this->assertNull($result->claim->dueDate);
        $this->assertSame(5000.0, $result->claim->amount);
    }

    public function testAmountAsNumericStringIsCoercedToFloat(): void
    {
        $aiContent = json_encode([
            'claim' => [
                'amount' => '7532.70', // numeric string from AI
                'currency' => 'RON',
            ],
            'globalConfidence' => 0.85,
        ]);
        $strategy = $this->makeStrategy(llmClient: $this->fakeLlmClient($aiContent));

        $result = $strategy->extract($this->makeDocument(id: 83));

        $this->assertNotNull($result->claim);
        $this->assertSame(7532.7, $result->claim->amount);
    }

    public function testExtractAcceptsAiResponseWrappedInMarkdownFence(): void
    {
        // Vision tends more than text-only to wrap JSON in ```json ... ``` —
        // parseAiResponse must strip the fence cleanly.
        $aiContent = "```json\n" . json_encode([
            'creditor' => ['name' => 'SC Z', 'confidencePerField' => ['name' => 0.9]],
            'globalConfidence' => 0.88,
        ]) . "\n```";

        $strategy = $this->makeStrategy(llmClient: $this->fakeLlmClient($aiContent));

        $result = $strategy->extract($this->makeDocument(id: 84));

        // Real assertion: markdown-fenced JSON is parsed correctly.
        // globalConfidence semantics covered in dedicated tests.
        $this->assertGreaterThan(0.0, $result->globalConfidence);
        $this->assertSame('SC Z', $result->creditor?->name);
    }

    // ---------- GDPR transparency log + defensive null guard ----------

    public function testGdprTransparencyLogCarriesDocumentIdUserIdMimeAndSize(): void
    {
        // Reg. UE 2016/679 art. 30 — registru activități prelucrare. The event
        // marking a binary transfer to Anthropic must be reconstructable: who
        // (userId), what (documentId + mimeType), how much (fileSize). If any
        // field disappears in a refactor, an ANSPDCP audit can't trace the
        // transfer back to the operator-of-record.
        $logger = $this->capturingLogger();
        $strategy = $this->makeStrategy(
            llmClient: $this->fakeLlmClient(json_encode(['creditor' => ['name' => 'X'], 'globalConfidence' => 0.8])),
            logger: $logger,
        );

        $strategy->extract($this->makeDocument(id: 85, mime: 'image/png'));

        $transparencyEvent = null;
        foreach ($logger->records as $record) {
            if ($record['message'] === 'extraction.ai_vision.binary_sent_unmasked') {
                $transparencyEvent = $record;
                break;
            }
        }
        $this->assertNotNull($transparencyEvent, 'GDPR transparency event must be logged on every binary transfer');
        $this->assertSame('info', $transparencyEvent['level']);
        $this->assertSame(85, $transparencyEvent['context']['documentId']);
        $this->assertSame('42', $transparencyEvent['context']['userId']);
        $this->assertSame('image/png', $transparencyEvent['context']['mimeType']);
        $this->assertGreaterThan(0, $transparencyEvent['context']['fileSize']);
    }

    public function testExtractReturnsZeroConfidenceIfMimeBecomesUnsupportedBetweenSupportsAndExtract(): void
    {
        // Defensive guard inside extract(): if a Document object is mutated
        // between supports() and extract() (different orchestrators / replay
        // attacks / test-only scenarios), buildVisionContentBlock returns
        // null on unrecognised MIME and the strategy fails closed instead of
        // sending an empty / malformed content block to Anthropic.
        $llm = $this->fakeLlmClient('{"creditor":{}}');
        $strategy = $this->makeStrategy(llmClient: $llm);
        $document = $this->makeDocument(id: 86, mime: 'application/x-not-supported');

        $result = $strategy->extract($document);

        $this->assertSame(0.0, $result->globalConfidence);
        $this->assertNull($llm->lastMessages, 'LLM must NOT be invoked when content block construction fails');
    }

    // ---------- prompt selection and classification ----------

    public function testAnUndeclaredTypeGetsTheClassifyingPromptAndItsBudget(): void
    {
        $llm = $this->fakeLlmClient('{"creditor":{"name":"Alfa SRL"}}');
        $strategy = $this->makeStrategy(llmClient: $llm);

        $strategy->extract($this->makeDocument());

        // The classifying pass also sees invoices, and it is the only one that
        // has to emit a classification on top of the extraction. Budgeting it
        // below the specialised invoice prompt made the pass over an undeclared
        // invoice the tightest in the system.
        $this->assertSame(16000, $llm->lastMaxTokens);
        $this->assertArrayHasKey('classification', $llm->lastOutputSchema['properties']);
    }

    public function testADeclaredTypeGetsItsOwnPromptAndBudget(): void
    {
        $llm = $this->fakeLlmClient('{"claim":{"amount":100}}');
        $strategy = $this->makeStrategy(llmClient: $llm);

        $strategy->extract($this->makeDocument(documentType: DocumentType::FACTURA));

        $this->assertSame(16000, $llm->lastMaxTokens);
        // A type the lawyer already declared is not re-asked; the model would
        // otherwise be invited to contradict it.
        $this->assertArrayNotHasKey('classification', $llm->lastOutputSchema['properties']);
        $this->assertStringContainsString('FACTURĂ', $llm->lastMessages[1]['content']);
    }

    public function testTheStableSystemBlockIsMarkedForCaching(): void
    {
        $llm = $this->fakeLlmClient('{"claim":{"amount":100}}');
        $strategy = $this->makeStrategy(llmClient: $llm);

        $strategy->extract($this->makeDocument());

        $this->assertTrue($llm->lastCacheSystemPrompt);
        $this->assertSame('system', $llm->lastMessages[0]['role']);
        $this->assertSame('user', $llm->lastMessages[1]['role']);
    }

    public function testAClassifiedDocumentCarriesTheDetectedTypeAndScore(): void
    {
        $llm = $this->fakeLlmClient(json_encode([
            'classification' => ['type' => 'factura', 'confidence' => 0.93, 'rationale' => 'antet de factură fiscală'],
            'claim' => ['amount' => 1200.0, 'confidencePerField' => ['amount' => 0.9]],
        ]));
        $strategy = $this->makeStrategy(llmClient: $llm);

        $result = $strategy->extract($this->makeDocument());

        $this->assertNotNull($result->classification);
        $this->assertSame(DocumentType::FACTURA, $result->classification->type);
        $this->assertSame(0.93, $result->classification->confidence);
        $this->assertSame('antet de factură fiscală', $result->classification->rationale);
    }

    public function testAnUnknownClassificationValueIsDropped(): void
    {
        $llm = $this->fakeLlmClient('{"classification":{"type":"bon_fiscal","confidence":0.9},"claim":{"amount":10}}');
        $strategy = $this->makeStrategy(llmClient: $llm);

        $result = $strategy->extract($this->makeDocument());

        $this->assertNull($result->classification);
    }

    public function testAGeneratedTypeIsRefusedAsAClassification(): void
    {
        // The platform produces these; accepting one would relabel a piece of
        // evidence as a filing the application generated itself.
        $llm = $this->fakeLlmClient('{"classification":{"type":"cerere_op","confidence":0.99},"claim":{"amount":10}}');
        $strategy = $this->makeStrategy(llmClient: $llm);

        $result = $strategy->extract($this->makeDocument());

        $this->assertNull($result->classification);
    }

    public function testTheClassificationScoreIsClamped(): void
    {
        $llm = $this->fakeLlmClient('{"classification":{"type":"contract","confidence":7},"claim":{"amount":10}}');
        $strategy = $this->makeStrategy(llmClient: $llm);

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(1.0, $result->classification->confidence);
    }

    public function testAResponseCarryingOnlyAClassificationIsStillAccepted(): void
    {
        // A handover report with no parties and no amount is a legitimate
        // outcome, not a parse failure.
        $llm = $this->fakeLlmClient('{"classification":{"type":"proces_verbal","confidence":0.88}}');
        $strategy = $this->makeStrategy(llmClient: $llm);

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(DocumentType::PROCES_VERBAL, $result->classification->type);
        $this->assertNull($result->failureReason);
    }

    public function testCoverageIsScoredAgainstTheDetectedType(): void
    {
        $llm = $this->fakeLlmClient(json_encode([
            'classification' => ['type' => 'extras_cont', 'confidence' => 0.9],
            'creditor' => ['name' => 'Alfa SRL', 'iban' => 'RO49AAAA1B31007593840000', 'bankName' => 'BT',
                'confidencePerField' => ['name' => 1.0, 'iban' => 1.0, 'bankName' => 1.0]],
            'debtor' => ['name' => 'Beta SRL', 'cui' => '123', 'confidencePerField' => ['name' => 1.0, 'cui' => 1.0]],
            'claim' => ['description' => 'plăți', 'confidencePerField' => ['description' => 1.0]],
        ]));
        $strategy = $this->makeStrategy(llmClient: $llm);

        $result = $strategy->extract($this->makeDocument());

        // Under the full field set this would score around 0.24 and be treated
        // as a poor extraction even though the statement was read completely.
        $this->assertSame(1.0, $result->globalConfidence);
    }

    public function testTheAuditTrailRecordsWhichPromptRanAndWhatItDetected(): void
    {
        $audit = $this->fakeAuditLogService();
        $llm = $this->fakeLlmClient('{"classification":{"type":"contract","confidence":0.8},"claim":{"amount":10,"confidencePerField":{"amount":0.9}}}');
        $strategy = $this->makeStrategy(llmClient: $llm, auditLogService: $audit);

        $strategy->extract($this->makeDocument());

        $logged = $audit->loggedCalls[0]['newData'];
        $this->assertSame('generic', $logged['prompt']);
        $this->assertSame('contract', $logged['detectedType']);
        $this->assertSame(0.8, $logged['detectedTypeConfidence']);
        $this->assertArrayHasKey('cacheReadInputTokens', $logged);
    }

    public function testThePayloadIsMarkedWithTheCurrentSchemaVersion(): void
    {
        $llm = $this->fakeLlmClient('{"claim":{"amount":10,"confidencePerField":{"amount":0.9}}}');
        $strategy = $this->makeStrategy(llmClient: $llm);

        $result = $strategy->extract($this->makeDocument());

        $this->assertSame(2, $result->schemaVersion);
        $this->assertSame(2, $result->toArray()['schemaVersion']);
    }
}
