<?php

namespace App\Tests\Service\Extraction;

use App\DTO\Ocr\OcrResult;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Service\AuditLogService;
use App\Service\Extraction\OcrTextExtractionStrategy;
use App\Service\Llm\AnthropicApiClient;
use App\Service\Ocr\OcrServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Nivel 2 integration tests — `OcrTextExtractionStrategy` with the REAL
 * {@see AnthropicApiClient} in front of a MockHttpClient (replays fixtures
 * recorded under tests/fixtures/llm/), the REAL {@see App\Util\PiiMasker}
 * round-trip, and a fake OcrServiceInterface that returns hand-crafted text
 * (so we don't need to run Tesseract here — that's already exercised in
 * tests/Service/Ocr/).
 *
 * Why a fake OCR layer: Tesseract takes 5–30s per call and is already covered
 * by Pas 2.5.5 tests. Faking it lets this test focus on the
 * mask → AI → restore → audit pipeline, with deterministic OCR output that
 * intentionally contains a real-checksum CNP and a valid-shape IBAN to
 * exercise the GDPR round-trip.
 *
 * Pair with:
 *   - {@see OcrTextExtractionStrategyTest}            — Nivel 1 unit (fakes everywhere).
 *   - {@see CascadeIntegrationTest}                   — Nivel 3 cascade with all strategies wired.
 */
class OcrTextExtractionStrategyIntegrationTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../fixtures/llm';

    /**
     * Builds a strategy whose AnthropicApiClient replays a fixture JSON file.
     * Returns the captured request body (set by the MockHttpClient callable)
     * via the wrapped array reference, so tests can inspect what crossed the
     * boundary toward the AI.
     *
     * @param array<string, mixed> $captured
     */
    private function makeStrategyReplaying(string $fixtureFilename, ?array &$captured = null): OcrTextExtractionStrategy
    {
        $captured = ['body' => null];
        $fixturePath = self::FIXTURES_DIR . '/' . $fixtureFilename;
        $body = file_get_contents($fixturePath);
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
            anthropicApiKey: 'sk-ant-test-key',
            anthropicModel: 'claude-sonnet-4-6',
            logger: new NullLogger(),
        );

        return new OcrTextExtractionStrategy(
            ocrService: $this->fakeOcrServiceWithRealisticPiiText(),
            llmClient: $anthropicClient,
            auditLogService: $this->captureAuditLogService(),
            extractionAiTextLimiter: $this->noLimitFactory(),
            uploadsDir: '/tmp/uploads-not-touched',
            anthropicApiKey: 'sk-ant-test-key',
            logger: new NullLogger(),
        );
    }

    private function fakeOcrServiceWithRealisticPiiText(): OcrServiceInterface
    {
        // Realistic invoice-style scanned-document text. Contains:
        //   - Two CUIs (creditor RO15193236, debtor RO14186770) — both ANAF-checksum-valid.
        //   - One IBAN (RO49AAAA1B31007593840000) — Romanian shape valid.
        //   - One CNP (1980715221232) — OUG 97/2005 checksum-valid (Pas 2.5.4 fixture).
        $text = <<<TEXT
CONTRACT DE PRESTĂRI SERVICII NR. 042/2026

Părțile:
Creditor: SC Foo Consulting SRL, sediul în Str. Exemplu nr. 12, București,
CUI RO15193236, înregistrată la Registrul Comerțului J40/123/2020,
cont bancar RO49AAAA1B31007593840000 deschis la BCR.

Debitor: Popescu Maria, persoană fizică, domiciliată în Str. Test nr. 5,
Cluj-Napoca, CNP 1980715221232.

Sau alternativ, dacă debitorul e persoană juridică:
Debitor PJ: SC Bar Distribution SRL, CUI RO14186770.

Obiectul contractului: prestare de servicii de consultanță conform anexei.
Suma totală: 6.009,50 RON, scadenta la data de 15.06.2026, conform OUG 13/2011.
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
            ['id' => 'test_no_limit', 'policy' => 'no_limit'],
            new InMemoryStorage(),
        );
    }

    private function makeDocument(int $id = 100, string $mime = 'image/png'): Document
    {
        // setAccessible() on ReflectionProperty is a no-op in PHP 8.1+ — skip.
        $user = new User();
        (new \ReflectionClass($user))->getProperty('id')->setValue($user, 7);

        $case = new LegalCase();
        $case->setUser($user);

        $document = new Document();
        $document->setLegalCase($case);
        $document->setStoredFilename('scan.png');
        $document->setOriginalFilename('scan.png');
        $document->setFileSize(2048);
        $document->setMimeType($mime);
        (new \ReflectionClass($document))->getProperty('id')->setValue($document, $id);

        return $document;
    }

    // ---------- tests ----------

    public function testExtractMasksPiiBeforeRequestAndRestoresAfterResponse(): void
    {
        $captured = [];
        $strategy = $this->makeStrategyReplaying('anthropic-success-with-pii.json', $captured);
        $document = $this->makeDocument();

        $result = $strategy->extract($document);

        // 1. Request body sent to Anthropic must NOT contain raw PII.
        $this->assertNotNull($captured['body']);
        $this->assertStringNotContainsString('1980715221232', (string) $captured['body'], 'CNP must be masked in prompt');
        $this->assertStringNotContainsString('RO49AAAA1B31007593840000', (string) $captured['body'], 'IBAN must be masked in prompt');
        $this->assertStringContainsString('***-***-1232', (string) $captured['body'], 'CNP placeholder must reach the AI');
        $this->assertStringContainsString('IBAN_PLACEHOLDER_001', (string) $captured['body'], 'IBAN placeholder must reach the AI');

        // 2. Result has PII restored from the AI's structured response.
        $this->assertSame(OcrTextExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertSame(0.93, $result->globalConfidence);
        $this->assertNotNull($result->creditor);
        $this->assertSame('SC Foo SRL', $result->creditor->name);
        $this->assertSame('15193236', $result->creditor->cui);
        $this->assertSame('RO49AAAA1B31007593840000', $result->creditor->iban, 'IBAN must be restored from placeholder');

        $this->assertNotNull($result->debtor);
        $this->assertSame('Popescu Maria', $result->debtor->name);
        $this->assertSame('1980715221232', $result->debtor->personalId, 'CNP must be restored from placeholder');

        $this->assertNotNull($result->claim);
        $this->assertSame(6009.50, $result->claim->amount);
        $this->assertSame('RON', $result->claim->currency);
        $this->assertEquals(new \DateTimeImmutable('2026-06-15'), $result->claim->dueDate);
    }

    public function testExtractRequestShapeMatchesAnthropicMessagesApi(): void
    {
        $captured = [];
        $strategy = $this->makeStrategyReplaying('anthropic-success-with-pii.json', $captured);

        $strategy->extract($this->makeDocument());

        $body = json_decode((string) $captured['body'], true);
        $this->assertIsArray($body);
        $this->assertSame('claude-sonnet-4-6', $body['model']);
        $this->assertSame(2048, $body['max_tokens']);
        $this->assertCount(2, $body['messages']);
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertSame('user', $body['messages'][1]['role']);
        // System message must establish the JSON-only output contract.
        $this->assertStringContainsString('JSON', $body['messages'][0]['content']);
        // User message must contain the OCR text under the marker.
        $this->assertStringContainsString('TEXT OCR:', $body['messages'][1]['content']);
    }

    public function testExtractWritesAuditLogWithoutSensitiveContent(): void
    {
        $captured = [];
        $strategy = $this->makeStrategyReplaying('anthropic-success-with-pii.json', $captured);
        // We need to fish the audit service out — recreate the strategy with our own.
        $audit = $this->captureAuditLogService();
        $strategy = new OcrTextExtractionStrategy(
            ocrService: $this->fakeOcrServiceWithRealisticPiiText(),
            llmClient: new AnthropicApiClient(
                httpClient: new MockHttpClient(static fn (): MockResponse => new MockResponse(
                    (string) file_get_contents(self::FIXTURES_DIR . '/anthropic-success-with-pii.json'),
                    ['http_code' => 200],
                )),
                anthropicApiKey: 'sk-ant-test-key',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $audit,
            extractionAiTextLimiter: $this->noLimitFactory(),
            uploadsDir: '/tmp',
            anthropicApiKey: 'sk-ant-test-key',
            logger: new NullLogger(),
        );

        $strategy->extract($this->makeDocument());

        $this->assertCount(1, $audit->loggedCalls);
        $entry = $audit->loggedCalls[0];
        $this->assertSame('AI_EXTRACTION_COMPLETED', $entry['action']);
        $this->assertSame(AuditLogService::CATEGORY_AI_EXTRACTION, $entry['category']);

        $persisted = json_encode($entry['newData']);
        $this->assertNotFalse($persisted);
        // Audit metadata must NOT contain raw OCR text or PII originals.
        $this->assertStringNotContainsString('Popescu Maria', $persisted);
        $this->assertStringNotContainsString('1980715221232', $persisted);
        $this->assertStringNotContainsString('RO49AAAA1B31007593840000', $persisted);
        $this->assertStringNotContainsString('TEXT OCR', $persisted);
        // ...but it MUST carry token counts and a stable response hash for traceability.
        $this->assertSame(837, $entry['newData']['tokensIn']);
        $this->assertSame(312, $entry['newData']['tokensOut']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $entry['newData']['responseHash']);
    }
}
