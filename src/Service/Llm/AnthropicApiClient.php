<?php

namespace App\Service\Llm;

use App\DTO\Llm\LlmResponse;
use App\Enum\LlmFinishReason;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP wrapper around Anthropic's Messages API
 * ({@link https://docs.anthropic.com/en/api/messages}). Sole implementation
 * of {@see LlmClientInterface} for MVP. ANALIZA-FLUXURI:448 documents the
 * planned V2 alternative (Ollama self-hosted with Qwen2-VL / LLaVA), which
 * will introduce an `OllamaApiClient` next to this one — at that point
 * services.yaml flips the interface alias and callers stay untouched.
 *
 * Behaviour:
 *   - POST /v1/messages with model + max_tokens + messages + optional document
 *     parts (for vision in Pas 2.5.8).
 *   - Auth via `x-api-key` header. `anthropic-version` pinned to 2023-06-01;
 *     bumping is a deliberate decision (response shape may change).
 *   - 4xx (auth, malformed request, rate-limit) → LlmException with the
 *     provider error preserved. Logger.error includes status + Anthropic error
 *     type + path; NEVER includes prompt content or any PII.
 *   - 5xx surfaces as LlmException too. The request itself isn't retried at
 *     this layer (no built-in `RetryableHttpClient` decoration in MVP — Pas
 *     2.6 async messenger handler will retry on the message level instead).
 *   - Response body parsed and mapped into the neutral {@see LlmResponse}.
 *
 * GDPR & subprocessing notes (Reg. UE 2016/679 art. 28, 30, 44–49):
 *   - Anthropic's default API retention is 30 days for prompts and responses
 *     (no longer-term storage; verified in the Anthropic Trust Center). After
 *     this window the data is deleted from Anthropic's systems. Operators
 *     deploying LexRecovery to production MUST sign Anthropic's enterprise
 *     DPA before processing real client data through this client.
 *   - The endpoint `api.anthropic.com` resolves to US-based infrastructure.
 *     Each request constitutes a transborder data transfer under GDPR
 *     art. 44–49 — it is permitted only when the DPA includes valid
 *     Standard Contractual Clauses (SCCs) for the controller→processor
 *     transfer EU→US. The application layer assumes this contractual basis
 *     is in place; no technical enforcement.
 *   - Caller-side responsibility: every prompt body that reaches this method
 *     must already have CNP/IBAN masked via {@see App\Util\PiiMasker}.
 *     This client does NOT inspect message contents, so masking is a hard
 *     contract on the caller per {@see LlmClientInterface::complete()} docblock.
 *   - Response body size is capped at {@see self::MAX_RESPONSE_BYTES} before
 *     JSON decode — defensive guard against pathological model outputs that
 *     would otherwise OOM the decode step or overflow the persisted JSON
 *     column.
 */
final class AnthropicApiClient implements LlmClientInterface
{
    private const API_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    /** Seconds before the request is aborted. Vision calls can take 20–30s. */
    private const REQUEST_TIMEOUT = 60;

    /**
     * Hard cap on response body size before JSON decode. The structured-extraction
     * prompts used by Pas 2.5.7 / 2.5.8 produce responses well under 10 KB; a
     * 1 MB ceiling leaves ~100× headroom while protecting against pathological
     * AI outputs (e.g., a 50 MB `claim.description`) that would either OOM the
     * `json_decode` step or push the resulting array past the MySQL JSON column
     * limit when persisted into `Document.extractedData`.
     */
    private const MAX_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $anthropicApiKey,
        private readonly string $anthropicModel,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function complete(
        array $messages,
        int $maxTokens = 2048,
        ?array $documentParts = null,
    ): LlmResponse {
        if ($this->anthropicApiKey === '') {
            throw new LlmException('ANTHROPIC_API_KEY is not configured');
        }

        $body = $this->buildRequestBody($messages, $maxTokens, $documentParts);

        try {
            $response = $this->httpClient->request('POST', self::API_ENDPOINT, [
                'headers' => [
                    'x-api-key' => $this->anthropicApiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'content-type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => self::REQUEST_TIMEOUT,
            ]);
            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(throw: false);
        } catch (TransportException $e) {
            // Log only the exception class + code, not the message — Symfony
            // TransportException messages may include URL fragments / request
            // metadata; classification is enough for ops triage and avoids
            // accidental sensitive-data leakage.
            $this->logger->error('llm.anthropic.transport_error', [
                'exceptionClass' => $e::class,
                'code' => $e->getCode(),
            ]);
            throw new LlmException('Anthropic transport error', 0, $e);
        } catch (HttpExceptionInterface $e) {
            // Catch-all for any other Symfony HTTP exception types not surfaced via getStatusCode.
            $this->logger->error('llm.anthropic.http_exception', [
                'exceptionClass' => $e::class,
                'code' => $e->getCode(),
            ]);
            throw new LlmException('Anthropic HTTP exception', 0, $e);
        }

        if ($statusCode >= 400) {
            $errorType = $this->extractErrorType($rawBody);
            $this->logger->error('llm.anthropic.http_error', [
                'status' => $statusCode,
                'errorType' => $errorType,
            ]);
            throw new LlmException(sprintf(
                'Anthropic API returned HTTP %d (type=%s)',
                $statusCode,
                $errorType ?? 'unknown',
            ));
        }

        // Defensive size check before JSON decode. Anthropic responses for our
        // prompts are tiny (<10 KB), but a buggy or hostile response could
        // exhaust memory at decode time or, post-decode, blow past the MySQL
        // JSON column limit when persisted into Document.extractedData.
        if (strlen($rawBody) > self::MAX_RESPONSE_BYTES) {
            $this->logger->error('llm.anthropic.response_too_large', [
                'size' => strlen($rawBody),
                'limit' => self::MAX_RESPONSE_BYTES,
            ]);
            throw new LlmException(sprintf(
                'Anthropic response exceeded %d bytes (got %d) — refusing to decode',
                self::MAX_RESPONSE_BYTES,
                strlen($rawBody),
            ));
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new LlmException('Anthropic response was not valid JSON');
        }

        return $this->mapResponse($decoded);
    }

    // ---------- request building ----------

    /**
     * @param array<int, array{role: string, content: string|array<int, array<string, mixed>>}> $messages
     * @param array<int, array<string, mixed>>|null $documentParts
     * @return array<string, mixed>
     */
    private function buildRequestBody(array $messages, int $maxTokens, ?array $documentParts): array
    {
        $body = [
            'model' => $this->anthropicModel,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];

        // Vision: append document parts (image content blocks) to the last user
        // message's content. Anthropic expects content as a structured array
        // of blocks, not a plain string, when images are involved.
        if ($documentParts !== null && $documentParts !== []) {
            $body['messages'] = $this->appendDocumentPartsToLastUserMessage($messages, $documentParts);
        }

        return $body;
    }

    /**
     * @param array<int, array{role: string, content: string|array<int, array<string, mixed>>}> $messages
     * @param array<int, array<string, mixed>> $documentParts
     * @return array<int, array{role: string, content: array<int, array<string, mixed>>}>
     */
    private function appendDocumentPartsToLastUserMessage(array $messages, array $documentParts): array
    {
        $lastUserIndex = null;
        foreach ($messages as $i => $message) {
            if (($message['role'] ?? null) === 'user') {
                $lastUserIndex = $i;
            }
        }

        if ($lastUserIndex === null) {
            // No user message to attach document parts to — surface an explicit error
            // rather than silently dropping the parts (would happen at MVP-time).
            throw new LlmException('Cannot attach document parts: no user message in the request');
        }

        $userContent = $messages[$lastUserIndex]['content'];
        $textBlock = is_string($userContent)
            ? [['type' => 'text', 'text' => $userContent]]
            : $userContent;

        $messages[$lastUserIndex]['content'] = array_merge($documentParts, $textBlock);

        return $messages;
    }

    // ---------- response parsing ----------

    /**
     * @param array<string, mixed> $decoded full Anthropic response body
     */
    private function mapResponse(array $decoded): LlmResponse
    {
        $content = $this->extractTextContent($decoded);
        $usage = $decoded['usage'] ?? [];

        return new LlmResponse(
            content: $content,
            tokensIn: (int) ($usage['input_tokens'] ?? 0),
            tokensOut: (int) ($usage['output_tokens'] ?? 0),
            finishReason: $this->mapStopReason($decoded['stop_reason'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function extractTextContent(array $decoded): string
    {
        $blocks = $decoded['content'] ?? null;
        if (!is_array($blocks)) {
            throw new LlmException('Anthropic response missing `content` array');
        }

        $texts = [];
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $texts[] = (string) $block['text'];
            }
        }

        if ($texts === []) {
            // Anthropic responses with only `tool_use` blocks (and no `text`)
            // are not supported by the current callers — surfacing as
            // LlmException prevents silent empty-string downstream where the
            // caller would try to JSON-decode "" and fail with confusing errors.
            throw new LlmException('Anthropic response contained no text blocks');
        }

        return implode("\n", $texts);
    }

    private function mapStopReason(mixed $stopReason): LlmFinishReason
    {
        return match ($stopReason) {
            'end_turn' => LlmFinishReason::COMPLETED,
            'max_tokens' => LlmFinishReason::MAX_TOKENS,
            'stop_sequence' => LlmFinishReason::STOP_SEQUENCE,
            default => LlmFinishReason::OTHER,
        };
    }

    private function extractErrorType(string $rawBody): ?string
    {
        if ($rawBody === '') {
            return null;
        }
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return null;
        }
        // Anthropic error envelope: { "type": "error", "error": { "type": "invalid_request_error", ... } }
        $err = $decoded['error'] ?? null;

        return is_array($err) && isset($err['type']) ? (string) $err['type'] : null;
    }
}
