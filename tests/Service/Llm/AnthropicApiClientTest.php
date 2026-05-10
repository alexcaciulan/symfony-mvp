<?php

namespace App\Tests\Service\Llm;

use App\DTO\Llm\LlmResponse;
use App\Enum\LlmFinishReason;
use App\Service\Llm\AnthropicApiClient;
use App\Service\Llm\LlmException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Mixed Nivel 1 (logic + DTO mapping) + Nivel 2 (integration with HTTP fixtures
 * recorded under tests/fixtures/llm/, replayed via MockHttpClient — no live
 * Anthropic call). Per regula 3 niveluri (memory feedback_test_coverage_3_layers.md):
 * Nivel 3 cascade is deferred to Pas 2.5.7, where OcrTextExtractionStrategy
 * wires AnthropicApiClient (via LlmClientInterface) into the orchestrator.
 *
 * Pattern aligned with App\Tests\Service\Company\AnafLookupServiceTest:
 * MockHttpClient with a callable response factory + last-request inspection
 * for assertions on the outgoing payload.
 */
class AnthropicApiClientTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../fixtures/llm';

    /**
     * @param list<MockResponse> $responses Sequence of responses to feed to MockHttpClient.
     */
    private function makeClient(array $responses): AnthropicApiClient
    {
        $mockClient = new MockHttpClient($responses, 'https://api.anthropic.com');

        return new AnthropicApiClient(
            httpClient: $mockClient,
            anthropicApiKey: 'test-key',
            anthropicModel: 'claude-sonnet-4-6',
            logger: new NullLogger(),
        );
    }

    private function fixture(string $filename): string
    {
        $contents = file_get_contents(self::FIXTURES_DIR . '/' . $filename);
        if ($contents === false) {
            throw new \RuntimeException("Fixture not readable: {$filename}");
        }

        return $contents;
    }

    // ---------- happy path ----------

    public function testCompleteReturnsParsedLlmResponseOnSuccess(): void
    {
        $client = $this->makeClient([
            new MockResponse($this->fixture('anthropic-success-text.json'), [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]),
        ]);

        $response = $client->complete([
            ['role' => 'user', 'content' => 'Extract: CUI RO15193236, Total 5000 RON'],
        ]);

        $this->assertInstanceOf(LlmResponse::class, $response);
        $this->assertSame(LlmFinishReason::COMPLETED, $response->finishReason);
        $this->assertSame(412, $response->tokensIn);
        $this->assertSame(87, $response->tokensOut);
        $this->assertStringContainsString('15193236', $response->content);
        $this->assertStringContainsString('14186770', $response->content);
    }

    public function testCompleteMapsMaxTokensFinishReason(): void
    {
        $client = $this->makeClient([
            new MockResponse($this->fixture('anthropic-max-tokens.json'), ['http_code' => 200]),
        ]);

        $response = $client->complete([['role' => 'user', 'content' => 'long prompt']], maxTokens: 16);

        $this->assertSame(LlmFinishReason::MAX_TOKENS, $response->finishReason);
    }

    public function testCompleteSendsExpectedRequestShape(): void
    {
        $captured = [];
        $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? [], 'body' => $options['body'] ?? null];

            return new MockResponse($this->fixture('anthropic-success-text.json'), ['http_code' => 200]);
        });

        $client = new AnthropicApiClient(
            httpClient: $mockClient,
            anthropicApiKey: 'sk-ant-test-key',
            anthropicModel: 'claude-sonnet-4-6',
            logger: new NullLogger(),
        );

        $client->complete([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://api.anthropic.com/v1/messages', $captured['url']);
        // Symfony renders headers as "Header: value" strings.
        $this->assertContains('x-api-key: sk-ant-test-key', $captured['headers']);
        $this->assertContains('anthropic-version: 2023-06-01', $captured['headers']);

        $body = json_decode((string) $captured['body'], true);
        $this->assertSame('claude-sonnet-4-6', $body['model']);
        $this->assertSame(2048, $body['max_tokens']);
        $this->assertSame([['role' => 'user', 'content' => 'hi']], $body['messages']);
    }

    public function testCompleteAppendsDocumentPartsToLastUserMessage(): void
    {
        $captured = null;
        $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = $options['body'] ?? null;

            return new MockResponse($this->fixture('anthropic-success-vision.json'), ['http_code' => 200]);
        });

        $client = new AnthropicApiClient(
            httpClient: $mockClient,
            anthropicApiKey: 'test-key',
            anthropicModel: 'claude-sonnet-4-6',
            logger: new NullLogger(),
        );

        $imagePart = [
            'type' => 'image',
            'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'iVBORw0KGgo...'],
        ];

        $response = $client->complete(
            messages: [['role' => 'user', 'content' => 'Extract data from this scan']],
            documentParts: [$imagePart],
        );

        $this->assertSame(LlmFinishReason::COMPLETED, $response->finishReason);
        $body = json_decode((string) $captured, true);
        // The user message's content should now be a structured array starting
        // with the image part and ending with the text block.
        $userContent = $body['messages'][0]['content'];
        $this->assertIsArray($userContent);
        $this->assertSame('image', $userContent[0]['type']);
        $this->assertSame('text', $userContent[1]['type']);
        $this->assertSame('Extract data from this scan', $userContent[1]['text']);
    }

    // ---------- error paths ----------

    public function testCompleteThrowsOnMissingApiKey(): void
    {
        $client = new AnthropicApiClient(
            httpClient: new MockHttpClient(),
            anthropicApiKey: '',
            anthropicModel: 'claude-sonnet-4-6',
            logger: new NullLogger(),
        );

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/API_KEY is not configured/');

        $client->complete([['role' => 'user', 'content' => 'x']]);
    }

    public function testCompleteThrowsLlmExceptionOn401AuthError(): void
    {
        $client = $this->makeClient([
            new MockResponse($this->fixture('anthropic-error-401.json'), ['http_code' => 401]),
        ]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/HTTP 401.*authentication_error/');

        $client->complete([['role' => 'user', 'content' => 'x']]);
    }

    public function testCompleteThrowsLlmExceptionOn429RateLimit(): void
    {
        $client = $this->makeClient([
            new MockResponse($this->fixture('anthropic-error-429.json'), ['http_code' => 429]),
        ]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/HTTP 429.*rate_limit_error/');

        $client->complete([['role' => 'user', 'content' => 'x']]);
    }

    public function testCompleteThrowsLlmExceptionOnMalformedJsonResponse(): void
    {
        $client = $this->makeClient([
            new MockResponse('this is not JSON', ['http_code' => 200]),
        ]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $client->complete([['role' => 'user', 'content' => 'x']]);
    }

    public function testCompleteThrowsLlmExceptionOnMissingContentBlock(): void
    {
        $client = $this->makeClient([
            new MockResponse(json_encode(['stop_reason' => 'end_turn']), ['http_code' => 200]),
        ]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/missing `content` array/');

        $client->complete([['role' => 'user', 'content' => 'x']]);
    }

    public function testCompleteThrowsWhenDocumentPartsHaveNoUserMessage(): void
    {
        $client = $this->makeClient([]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/no user message/');

        $client->complete(
            messages: [['role' => 'system', 'content' => 'You are an extractor']],
            documentParts: [['type' => 'image', 'source' => []]],
        );
    }

    public function testCompleteThrowsLlmExceptionOnTransportError(): void
    {
        // Simulate DNS / TCP / TLS failure surfaced by Symfony as TransportException.
        $mockClient = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Could not resolve host: api.anthropic.com');
        });
        $client = new AnthropicApiClient(
            httpClient: $mockClient,
            anthropicApiKey: 'test-key',
            anthropicModel: 'claude-sonnet-4-6',
            logger: new NullLogger(),
        );

        $this->expectException(LlmException::class);
        // Message must NOT leak the underlying transport message; it MUST be
        // generic and provider-classified. Original exception is preserved as
        // `previous` for triage.
        $this->expectExceptionMessage('Anthropic transport error');

        $client->complete([['role' => 'user', 'content' => 'x']]);
    }

    public function testCompleteThrowsLlmExceptionOn503ServerError(): void
    {
        $client = $this->makeClient([
            new MockResponse('{"type":"error","error":{"type":"overloaded_error","message":"Anthropic is overloaded"}}', [
                'http_code' => 503,
            ]),
        ]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/HTTP 503.*overloaded_error/');

        $client->complete([['role' => 'user', 'content' => 'x']]);
    }

    public function testCompleteThrowsWhenContentHasNoTextBlocks(): void
    {
        // Anthropic responses with only `tool_use` blocks (no text) must fail
        // loudly — current callers cannot consume an empty string and would
        // otherwise produce a confusing JSON-decode error downstream.
        $body = json_encode([
            'id' => 'msg_tooluse_only',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'tool_use', 'id' => 'toolu_xyz', 'name' => 'extract_invoice', 'input' => []],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 5],
        ]);
        $client = $this->makeClient([new MockResponse($body, ['http_code' => 200])]);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessageMatches('/no text blocks/');

        $client->complete([['role' => 'user', 'content' => 'x']]);
    }

    // ---------- enum + fixtures sanity ----------

    public function testFinishReasonEnumLabelsAreI18nKeys(): void
    {
        foreach (LlmFinishReason::cases() as $case) {
            $this->assertStringStartsWith('enum.llm_finish_reason.', $case->label());
        }
        $this->assertCount(4, LlmFinishReason::cases());
    }

    public function testFixturesAreCommittedAndReadable(): void
    {
        // Independent of any HTTP / network state — runs even if mock setup is
        // broken so an accidentally deleted fixture surfaces here, not as a
        // confusing fatal in another test.
        $expected = [
            'anthropic-success-text.json',
            'anthropic-success-vision.json',
            'anthropic-error-401.json',
            'anthropic-error-429.json',
            'anthropic-max-tokens.json',
        ];

        foreach ($expected as $filename) {
            $path = self::FIXTURES_DIR . '/' . $filename;
            $this->assertFileExists($path);
            $this->assertGreaterThan(20, filesize($path), "Fixture {$filename} suspiciously small");
            $decoded = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($decoded, "Fixture {$filename} is not valid JSON");
        }
    }
}
