<?php

namespace App\Tests\Service\Extraction;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Service\AuditLogService;
use App\Service\Extraction\AiVisionExtractionStrategy;
use App\Service\Llm\AnthropicApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Nivel 2 integration tests — `AiVisionExtractionStrategy` with the REAL
 * {@see AnthropicApiClient} in front of a MockHttpClient (replaying
 * `tests/fixtures/llm/anthropic-success-vision-rich.json`). The fixture
 * binary input is the existing real PNG `tests/fixtures/ocr/clean-text.png`
 * from Pas 2.5.5 (we don't care about its visual content here — the AI is
 * mocked; we only verify the request body shape and round-trip).
 *
 * Pair with:
 *   - {@see AiVisionExtractionStrategyTest}      — Nivel 1 unit (all fakes).
 *   - {@see CascadeIntegrationTest}              — Nivel 3 cascade end-to-end.
 */
class AiVisionExtractionStrategyIntegrationTest extends TestCase
{
    private const LLM_FIXTURES_DIR = __DIR__ . '/../../fixtures/llm';

    private const OCR_FIXTURES_DIR = __DIR__ . '/../../fixtures/ocr';

    /**
     * Builds a strategy whose AnthropicApiClient replays a fixture JSON file.
     * Returns the captured request body via the wrapped reference so tests
     * can inspect what crossed the boundary toward the AI.
     *
     * @param array<string, mixed> $captured
     */
    private function makeStrategyReplaying(string $fixtureFilename, ?array &$captured = null, ?AuditLogService $audit = null): AiVisionExtractionStrategy
    {
        $captured = ['body' => null];
        $body = file_get_contents(self::LLM_FIXTURES_DIR . '/' . $fixtureFilename);
        $this->assertNotFalse($body, "Fixture {$fixtureFilename} must be readable");

        $captureRef = &$captured;
        $mockHttp = new MockHttpClient(
            static function (string $method, string $url, array $options) use ($body, &$captureRef): MockResponse {
                $captureRef['body'] = $options['body'] ?? null;

                return new MockResponse($body, ['http_code' => 200]);
            },
        );

        $anthropicClient = new AnthropicApiClient(
            httpClient: $mockHttp,
            anthropicApiKey: 'sk-ant-vision-integration',
            anthropicModel: 'claude-sonnet-4-6',
            logger: new NullLogger(),
        );

        return new AiVisionExtractionStrategy(
            llmClient: $anthropicClient,
            auditLogService: $audit ?? $this->captureAuditLogService(),
            extractionAiVisionLimiter: $this->noLimitFactory(),
            // uploadsDir points at the OCR fixtures dir so we reuse the existing
            // real PNG without copying it elsewhere.
            uploadsDir: self::OCR_FIXTURES_DIR,
            anthropicApiKey: 'sk-ant-vision-integration',
            logger: new NullLogger(),
        );
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
            ['id' => 'aivision_int_no_limit', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
    }

    private function makeDocument(string $storedFilename, string $mime): Document
    {
        $user = new User();
        (new \ReflectionClass($user))->getProperty('id')->setValue($user, 7);

        $case = new LegalCase();
        $case->setUser($user);

        $document = new Document();
        $document->setLegalCase($case);
        $document->setStoredFilename($storedFilename);
        $document->setOriginalFilename($storedFilename);
        $document->setFileSize(2048);
        $document->setMimeType($mime);
        (new \ReflectionClass($document))->getProperty('id')->setValue($document, 100);

        return $document;
    }

    // ---------- tests ----------

    public function testExtractEndToEndProducesFullDtoFromFixtureReplay(): void
    {
        $captured = [];
        $strategy = $this->makeStrategyReplaying('anthropic-success-vision-rich.json', $captured);

        $result = $strategy->extract($this->makeDocument('clean-text.png', 'image/png'));

        // Vision returned the rich fixture: creditor + debtor + claim + globalConfidence 0.91.
        $this->assertSame(0.91, $result->globalConfidence);
        $this->assertSame('Alpha Servicii Comerciale SRL', $result->creditor?->name);
        $this->assertSame('15193236', $result->creditor?->cui);
        $this->assertSame('RO49AAAA1B31007593840000', $result->creditor?->iban);
        $this->assertSame('Beta Distribution SRL', $result->debtor?->name);
        $this->assertSame(7532.70, $result->claim?->amount);
        $this->assertEquals(new \DateTimeImmutable('2026-05-31'), $result->claim?->dueDate);
        $this->assertNull($result->rawOcrText);
    }

    public function testExtractRequestBodyConformsToAnthropicVisionMessagesShape(): void
    {
        $captured = [];
        $strategy = $this->makeStrategyReplaying('anthropic-success-vision-rich.json', $captured);

        $strategy->extract($this->makeDocument('clean-text.png', 'image/png'));

        $body = json_decode((string) $captured['body'], true);
        $this->assertIsArray($body);
        $this->assertSame('claude-sonnet-4-6', $body['model']);
        $this->assertSame(2048, $body['max_tokens']);
        // Anthropic Messages API contract: system prompt is top-level; only
        // `user`/`assistant` roles live in `messages`.
        $this->assertCount(1, $body['messages']);
        $this->assertSame('user', $body['messages'][0]['role']);
        $this->assertArrayHasKey('system', $body);
        $this->assertNotEmpty($body['system']);

        // The user message content must be an array of blocks (image + text)
        // because vision parts were appended. The image block carries the
        // base64-encoded fixture content.
        $userContent = $body['messages'][0]['content'];
        $this->assertIsArray($userContent, 'User message content must be a structured array when vision parts are present');
        $blockTypes = array_column($userContent, 'type');
        $this->assertContains('image', $blockTypes, 'Vision image block must be in the request');
        $this->assertContains('text', $blockTypes, 'User text prompt must be alongside the image');

        // The image block's media_type matches the document's MIME (image/png
        // here — clean-text.png). Source is base64 with non-empty data.
        $imageBlock = $userContent[array_search('image', $blockTypes, true)];
        $this->assertSame('image/png', $imageBlock['source']['media_type']);
        $this->assertNotEmpty($imageBlock['source']['data']);
        $this->assertNotFalse(base64_decode($imageBlock['source']['data'], true));
    }

    public function testExtractAuditsWithoutLeakingBinaryBase64Content(): void
    {
        $audit = $this->captureAuditLogService();
        $captured = [];
        $strategy = $this->makeStrategyReplaying('anthropic-success-vision-rich.json', $captured, $audit);

        $strategy->extract($this->makeDocument('clean-text.png', 'image/png'));

        $this->assertCount(1, $audit->loggedCalls);
        $entry = $audit->loggedCalls[0];
        $this->assertSame('AI_EXTRACTION_COMPLETED', $entry['action']);
        $this->assertSame(AuditLogService::CATEGORY_AI_EXTRACTION, $entry['category']);

        $persisted = json_encode($entry['newData']);
        $this->assertNotFalse($persisted);
        // Persisted metadata must NOT include base64 image data or large blobs.
        $this->assertStringNotContainsString('base64', strtolower($persisted));
        $this->assertStringNotContainsString('iVBORw0KGgo', $persisted, 'Raw PNG header must NEVER be persisted in audit');
        // ...but the cost-relevant + traceability metadata MUST be present.
        $this->assertSame(1842, $entry['newData']['tokensIn']);
        $this->assertSame(187, $entry['newData']['tokensOut']);
        $this->assertSame('image/png', $entry['newData']['mimeType']);
        $this->assertGreaterThan(0, $entry['newData']['fileSize']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $entry['newData']['responseHash']);
    }

    public function testExtractSendsRealScannedPdfAsDocumentContentBlock(): void
    {
        // Real-PDF smoke. `tests/fixtures/ocr/scanned-invoice.pdf` is a
        // ~50 KB DomPDF→ImageMagick rasterised PDF (no text layer) committed
        // by Pas 2.5.5. The strategy must detect application/pdf, build a
        // `document` content block (Claude 3.5+ native PDF), and send a
        // valid base64 payload that decodes back to the file's bytes.
        // Anthropic's transport is mocked — the assertion is on the wire
        // shape, not on how Claude would process it. This is the only test
        // exercising the `document` block path with a real PDF binary; the
        // unit-level PDF test uses a 4-byte stub.
        $captured = [];
        $strategy = $this->makeStrategyReplaying('anthropic-success-vision-rich.json', $captured);

        $strategy->extract($this->makeDocument('scanned-invoice.pdf', 'application/pdf'));

        $body = json_decode((string) $captured['body'], true);
        $this->assertIsArray($body);

        // Locate the `document` block in the user message content. After the
        // Anthropic-shape adaptation, `messages` contains only the user entry;
        // system is at body root.
        $userContent = $body['messages'][0]['content'];
        $this->assertIsArray($userContent);
        $documentBlock = null;
        foreach ($userContent as $block) {
            if (($block['type'] ?? null) === 'document') {
                $documentBlock = $block;
                break;
            }
        }
        $this->assertNotNull($documentBlock, '`document` content block must be present for application/pdf');
        $this->assertSame('base64', $documentBlock['source']['type']);
        $this->assertSame('application/pdf', $documentBlock['source']['media_type']);

        // Decoded base64 must equal the on-disk PDF bytes — proves the strategy
        // didn't truncate, modify, or substitute the binary.
        $decoded = base64_decode($documentBlock['source']['data'], true);
        $this->assertNotFalse($decoded, 'PDF base64 must decode cleanly');
        $expectedBytes = file_get_contents(self::OCR_FIXTURES_DIR . '/scanned-invoice.pdf');
        $this->assertSame($expectedBytes, $decoded, 'Sent PDF bytes must equal the on-disk fixture verbatim');

        // PDF magic header survived the round-trip — extra defence against
        // accidental encoding shenanigans.
        $this->assertStringStartsWith('%PDF', $decoded);
    }
}
