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

    /**
     * Seconds before the request is aborted. A single-page vision call takes
     * 20-30s, but a 20-page PDF at a high output budget runs well past a
     * minute, and aborting it wastes the tokens already spent.
     */
    private const REQUEST_TIMEOUT = 300;

    /**
     * Hard cap on response body size before JSON decode. The structured-extraction
     * prompts used by Pas 2.5.7 / 2.5.8 produce responses well under 10 KB; a
     * 1 MB ceiling leaves ~100× headroom while protecting against pathological
     * AI outputs (e.g., a 50 MB `claim.description`) that would either OOM the
     * `json_decode` step or push the resulting array past the MySQL JSON column
     * limit when persisted into `Document.extractedData`.
     */
    private const MAX_RESPONSE_BYTES = 1_048_576;

    /**
     * Grammar-compilation caps the provider enforces on a constrained-decoding
     * schema. Both are documented in the 400 the API returns when a schema
     * exceeds them; they are here so a schema that cannot be compiled is
     * detected before the call rather than by losing one.
     */
    private const MAX_OPTIONAL_SCHEMA_PARAMETERS = 24;

    private const MAX_UNION_SCHEMA_PARAMETERS = 16;

    /**
     * Model id prefixes that accept `output_config.format`. Matched as prefixes
     * so a dated snapshot of the same model still qualifies. Sonnet 4.6 is
     * absent on purpose: it is the model this application ran before, and it
     * would reject the parameter.
     */
    private const STRUCTURED_OUTPUT_MODELS = [
        'claude-opus-4-8',
        'claude-fable-5',
        'claude-mythos-5',
        'claude-sonnet-5',
        'claude-haiku-4-5',
        'claude-opus-4-5',
    ];

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
        bool $cacheSystemPrompt = false,
        ?array $outputSchema = null,
    ): LlmResponse {
        if ($this->anthropicApiKey === '') {
            throw new LlmException('ANTHROPIC_API_KEY is not configured');
        }

        $body = $this->buildRequestBody($messages, $maxTokens, $documentParts, $cacheSystemPrompt, $outputSchema);

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
            throw LlmException::transient('Anthropic transport error', previous: $e);
        } catch (HttpExceptionInterface $e) {
            // Catch-all for any other Symfony HTTP exception types not surfaced via getStatusCode.
            $this->logger->error('llm.anthropic.http_exception', [
                'exceptionClass' => $e::class,
                'code' => $e->getCode(),
            ]);
            throw LlmException::transient('Anthropic HTTP exception', previous: $e);
        }

        if ($statusCode >= 400) {
            $errorType = $this->extractErrorType($rawBody);
            $errorMessage = $this->extractErrorMessage($rawBody);
            $this->logger->error('llm.anthropic.http_error', [
                'status' => $statusCode,
                'errorType' => $errorType,
                // Anthropic error messages are diagnostic (e.g. "image exceeds 5MB",
                // "model not found"), not the user's prompt — safe to log for ops
                // triage. Truncated at 300 chars in case Anthropic ever returns
                // verbose validation output that would bloat the logs.
                'errorMessage' => $errorMessage !== null ? mb_substr($errorMessage, 0, 300) : null,
            ]);
            // A billing or capacity refusal arrives as a 400 that reads like a
            // bad request but is nothing to do with the document: exhausted
            // credits, a suspended account, an overloaded endpoint. It clears
            // when the account is topped up, so it is worth another attempt and
            // must be surfaced as our problem, not the file's.
            $providerUnavailable = $this->signalsProviderUnavailable($statusCode, $errorType, $errorMessage);
            // 429 and 5xx clear up on their own; the rest (bad request, unknown
            // model, refused payload, bad key) answer identically on every retry.
            $transient = $statusCode === 429 || $statusCode >= 500 || $providerUnavailable;
            throw new LlmException(
                sprintf('Anthropic API returned HTTP %d (type=%s)', $statusCode, $errorType ?? 'unknown'),
                transient: $transient,
                statusCode: $statusCode,
                providerUnavailable: $providerUnavailable,
            );
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
                'Anthropic response exceeded %d bytes (got %d), refusing to decode',
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
     * @param array<string, mixed>|null $outputSchema
     * @return array<string, mixed>
     */
    private function buildRequestBody(
        array $messages,
        int $maxTokens,
        ?array $documentParts,
        bool $cacheSystemPrompt = false,
        ?array $outputSchema = null,
    ): array {
        // Anthropic Messages API expects the system prompt as a TOP-LEVEL
        // `system` field, not as a `role: system` entry inside `messages`
        // (that's the OpenAI convention). Callers use the OpenAI-style shape
        // for portability; we adapt here. Multiple system entries are joined
        // with double-newlines so the order in the caller's intent is preserved.
        $systemParts = [];
        $userMessages = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'system') {
                if (is_string($message['content'] ?? null) && $message['content'] !== '') {
                    $systemParts[] = $message['content'];
                }
                continue;
            }
            $userMessages[] = $message;
        }

        $body = [
            'model' => $this->anthropicModel,
            'max_tokens' => $maxTokens,
            'messages' => $userMessages,
        ];

        // The system prompt goes out as a list of text blocks rather than one
        // string because `cache_control` attaches to a block. Callers keep
        // sending plain strings; the blocks are built here.
        if ($systemParts !== []) {
            $systemBlocks = [];
            foreach ($systemParts as $part) {
                $systemBlocks[] = ['type' => 'text', 'text' => $part];
            }
            // The schema travels in the system block as well as in
            // `output_config`. Three reasons, all load-bearing: a model without
            // constrained decoding would otherwise be told nothing about the
            // shape it must return; the block is stable across every request
            // that uses the same prompt, so it belongs inside the cached prefix
            // rather than outside it; and the prefix has to clear the provider's
            // minimum cacheable length or the breakpoint below is silently
            // ignored (no error, cache_read_input_tokens stays 0 forever).
            // Measured against Opus 4.8, whose minimum is 4096 tokens: the
            // extraction system block alone is ~3.5k, and ~8.1k with the schema.
            // A future edit that shrinks either one back under 4096 turns
            // caching off without any visible symptom.
            if ($outputSchema !== null) {
                $systemBlocks[] = ['type' => 'text', 'text' => self::renderSchemaBlock($outputSchema)];
            }
            if ($cacheSystemPrompt) {
                // One breakpoint, on the last system block. Rendering order is
                // tools, then system, then messages, so this covers the whole
                // stable prefix while leaving the document block and the
                // per-document instructions (which differ every call) outside
                // the cached span.
                $lastIndex = count($systemBlocks) - 1;
                $systemBlocks[$lastIndex]['cache_control'] = ['type' => 'ephemeral'];
            }
            $body['system'] = $systemBlocks;
        }

        // Constrained decoding, when the model supports it. The response then
        // matches the schema by construction, which is what lets callers drop
        // JSON repair and keep only semantic validation. On a model without
        // support the parameter is omitted rather than sent and rejected: the
        // call still returns usable JSON, just without the guarantee.
        if ($outputSchema !== null && $this->supportsStructuredOutputs() && self::fitsGrammarLimits($outputSchema)) {
            $body['output_config'] = [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $outputSchema,
                ],
            ];
        } elseif ($outputSchema !== null) {
            // Degradation, not silence: the schema is still in the system block
            // above, so the model knows the shape it has to produce. What is
            // lost is the guarantee, which is why this is a warning.
            $this->logger->warning('llm.anthropic.structured_outputs_unavailable', [
                'model' => $this->anthropicModel,
                'optionalParameters' => self::countOptionalParameters($outputSchema),
                'unionParameters' => self::countUnionParameters($outputSchema),
            ]);
        }

        // Vision: append document parts (image content blocks) to the last user
        // message's content. Anthropic expects content as a structured array
        // of blocks, not a plain string, when images are involved.
        if ($documentParts !== null && $documentParts !== []) {
            $body['messages'] = $this->appendDocumentPartsToLastUserMessage($userMessages, $documentParts);
        }

        return $body;
    }

    /**
     * Whether constrained decoding can compile this schema.
     *
     * The provider builds a grammar from the schema, and the cost of that
     * grammar grows with the number of choices in it, so it caps both the
     * optional parameters and the union-typed ones. Over either cap the request
     * is rejected outright with a 400, which costs a whole extraction rather
     * than a guarantee. Checking here keeps the constraint where the limits are
     * known, and lets a caller whose payload is wider than the cap fall back to
     * the schema copy in the prompt, which every model can read.
     *
     * @param array<string, mixed> $schema
     */
    private static function fitsGrammarLimits(array $schema): bool
    {
        return self::countOptionalParameters($schema) <= self::MAX_OPTIONAL_SCHEMA_PARAMETERS
            && self::countUnionParameters($schema) <= self::MAX_UNION_SCHEMA_PARAMETERS;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function countOptionalParameters(array $schema): int
    {
        $count = 0;
        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            foreach ($properties as $name => $property) {
                if (!in_array($name, $required, true)) {
                    ++$count;
                }
                if (is_array($property)) {
                    $count += self::countOptionalParameters($property);
                }
            }
        }
        foreach (self::branches($schema) as $branch) {
            $count += self::countOptionalParameters($branch);
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function countUnionParameters(array $schema): int
    {
        $count = 0;
        if (is_array($schema['anyOf'] ?? null) || is_array($schema['type'] ?? null)) {
            ++$count;
        }
        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            foreach ($properties as $property) {
                if (is_array($property)) {
                    $count += self::countUnionParameters($property);
                }
            }
        }
        foreach (self::branches($schema) as $branch) {
            $count += self::countUnionParameters($branch);
        }

        return $count;
    }

    /**
     * The sub-schemas of a union or an array, as a flat list.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<array<string, mixed>>
     */
    private static function branches(array $schema): array
    {
        $branches = [];
        foreach (['anyOf', 'allOf', 'oneOf'] as $keyword) {
            if (is_array($schema[$keyword] ?? null)) {
                foreach ($schema[$keyword] as $branch) {
                    if (is_array($branch)) {
                        $branches[] = $branch;
                    }
                }
            }
        }
        if (is_array($schema['items'] ?? null)) {
            $branches[] = $schema['items'];
        }

        return $branches;
    }

    /**
     * The schema as prompt text, for the model to read alongside the
     * constrained-decoding copy.
     *
     * Encoded compactly rather than pretty-printed: the same schema costs
     * measurably fewer tokens without the indentation, and nothing reads this
     * block but the model.
     *
     * @param array<string, mixed> $outputSchema
     */
    private static function renderSchemaBlock(array $outputSchema): string
    {
        return "The response MUST be a single JSON object conforming to this JSON Schema. "
            . "Every property is required; use null for anything the source does not contain.\n"
            . json_encode(
                $outputSchema,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
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
            // Absent on providers or plans without a prompt cache, and on the
            // first call of a batch, where nothing has been written yet.
            cacheReadInputTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
            cacheCreationInputTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
        );
    }

    /**
     * Whether the configured model can be asked to conform to a JSON Schema.
     * The list is a deliberate allow-list rather than an attempt-and-recover:
     * an unsupported model answers with a 400, and discovering that per request
     * would cost a failed extraction each time the model is changed.
     */
    private function supportsStructuredOutputs(): bool
    {
        foreach (self::STRUCTURED_OUTPUT_MODELS as $prefix) {
            if (str_starts_with($this->anthropicModel, $prefix)) {
                return true;
            }
        }

        return false;
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

    /**
     * A 400/403 whose body names a billing, credit, quota or account-state
     * problem, or any 402/529: the provider will not serve us, but the request
     * itself was fine. Anthropic returns "credit balance is too low" as a 400
     * invalid_request_error, which is otherwise indistinguishable from a genuine
     * bad request, so the message is matched.
     */
    private function signalsProviderUnavailable(int $statusCode, ?string $errorType, ?string $errorMessage): bool
    {
        if ($statusCode === 402 || $statusCode === 529) {
            return true;
        }
        if ($errorType === 'overloaded_error') {
            return true;
        }
        if ($errorMessage === null || !in_array($statusCode, [400, 403], true)) {
            return false;
        }
        // Specific phrases only: a bare word like "insufficient" or "quota"
        // could sit in a genuine validation message ("insufficient parameters")
        // and misclassify a permanent bad request as a retriable outage.
        $needle = mb_strtolower($errorMessage);
        foreach (['credit balance', 'billing', 'plans & billing', 'insufficient credit', 'insufficient funds', 'quota exceeded', 'account has been suspended', 'account is suspended', 'overloaded'] as $marker) {
            if (str_contains($needle, $marker)) {
                return true;
            }
        }

        return false;
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

    private function extractErrorMessage(string $rawBody): ?string
    {
        if ($rawBody === '') {
            return null;
        }
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return null;
        }
        $err = $decoded['error'] ?? null;

        return is_array($err) && isset($err['message']) ? (string) $err['message'] : null;
    }
}
