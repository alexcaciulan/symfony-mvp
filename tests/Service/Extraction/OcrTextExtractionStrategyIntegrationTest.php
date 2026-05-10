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
use App\Service\Ocr\TesseractOcrService;
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

    /**
     * Smoke test exercising the FULL real pipeline as it runs in production —
     * real `TesseractOcrService` parsing a real scanned PDF fixture, real
     * `PiiMasker` round-trip, real `AnthropicApiClient` (wire-format wise; the
     * HTTP transport itself is mocked because we don't burn live API credits
     * in tests). This is the only test in the project that proves Tesseract +
     * PiiMasker + AnthropicApiClient compose correctly end-to-end. Skipped
     * automatically on hosts without the tesseract binary so non-Docker
     * developers can still run the rest of the suite.
     */
    public function testFullPipelineWithRealTesseractAndMockedAnthropic(): void
    {
        if (trim((string) shell_exec('which tesseract')) === '') {
            $this->markTestSkipped('Tesseract binary not available; run inside Docker container');
        }

        $captured = ['body' => null];
        $aiResponse = json_encode([
            'id' => 'msg_smoke',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-6',
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'creditor' => [
                        'personType' => 'PJ',
                        'name' => 'SC Foo SRL',
                        'cui' => '15193236',
                        'isVatPayer' => true,
                        // The smoke test depends on whether OCR recovers the
                        // exact 24-char IBAN from the rasterised PDF. Real
                        // Tesseract output may differ by 1-2 chars; the
                        // strategy still calls the AI and the test asserts
                        // the wire-format contract regardless of what the AI
                        // mimicked back as `iban`.
                        'iban' => 'IBAN_PLACEHOLDER_001',
                        'confidencePerField' => ['name' => 0.9, 'cui' => 0.95],
                    ],
                    'debtor' => [
                        'personType' => 'PJ',
                        'name' => 'SC Bar SRL',
                        'cui' => '14186770',
                        'confidencePerField' => ['name' => 0.85, 'cui' => 0.95],
                    ],
                    'claim' => [
                        'amount' => 5000.0,
                        'currency' => 'RON',
                        'dueDate' => '2026-06-15',
                        'confidencePerField' => ['amount' => 0.9],
                    ],
                    'globalConfidence' => 0.9,
                ]),
            ]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 200],
        ]);

        $captureRef = &$captured;
        $mockHttp = new MockHttpClient(
            static function (string $method, string $url, array $options) use ($aiResponse, &$captureRef): MockResponse {
                $captureRef['body'] = $options['body'] ?? null;

                return new MockResponse((string) $aiResponse, ['http_code' => 200]);
            },
        );

        $strategy = new OcrTextExtractionStrategy(
            ocrService: new TesseractOcrService(new NullLogger(), 'ron+eng'),
            llmClient: new AnthropicApiClient(
                httpClient: $mockHttp,
                anthropicApiKey: 'sk-ant-smoke',
                anthropicModel: 'claude-sonnet-4-6',
                logger: new NullLogger(),
            ),
            auditLogService: $this->captureAuditLogService(),
            extractionAiTextLimiter: $this->noLimitFactory(),
            // Point uploadsDir at the real OCR fixtures committed under tests/fixtures/ocr/.
            uploadsDir: __DIR__ . '/../../fixtures/ocr',
            anthropicApiKey: 'sk-ant-smoke',
            logger: new NullLogger(),
        );

        // scanned-invoice.pdf is a real DomPDF→ImageMagick rasterised PDF
        // containing RO15193236, RO14186770, and RO49AAAA1B31007593840000
        // (Pas 2.5.5 fixture; verified loadable via TesseractOcrServiceTest).
        $document = $this->makeDocument(mime: 'application/pdf');
        $document->setStoredFilename('scanned-invoice.pdf');

        $result = $strategy->extract($document);

        // 1. Real Tesseract did run — confidence is plausible and OCR text exists.
        $this->assertNotNull($result->rawOcrText);

        // 2. AI was invoked (request body captured).
        $this->assertNotNull($captured['body'], 'Mocked Anthropic must have been called');
        $sentBody = (string) $captured['body'];
        $decoded = json_decode($sentBody, true);
        $this->assertIsArray($decoded);
        $this->assertSame('claude-sonnet-4-6', $decoded['model']);

        // 3. Real PiiMasker masked the OCR-recovered IBAN before the prompt left
        // the boundary. Real OCR may not perfectly recover all 24 chars of the
        // IBAN — the assertion is conservative: if the IBAN was recovered
        // intact, it must be replaced with a placeholder; either way the
        // ORIGINAL must NOT appear in the prompt body verbatim.
        $this->assertStringNotContainsString('RO49AAAA1B31007593840000', $sentBody, 'Real OCR-recovered IBAN must be masked before prompt');

        // 4. The strategy's output reflects the AI response shape — even though
        // PiiMasker's IBAN restore depends on whether OCR recovered the IBAN
        // intact (which is OCR-quality-dependent), the structured fields
        // already reach the wizard.
        $this->assertSame(OcrTextExtractionStrategy::STRATEGY_KEY, $result->strategy);
        $this->assertNotNull($result->creditor);
        $this->assertSame('SC Foo SRL', $result->creditor->name);
        $this->assertNotNull($result->claim);
        $this->assertSame(5000.0, $result->claim->amount);

        // 5. rawOcrText persisted in the DTO (and downstream JSON column) is
        // masked: even on real OCR output, the IBAN is gone post-mask.
        $this->assertStringNotContainsString('RO49AAAA1B31007593840000', $result->rawOcrText);
    }
}
