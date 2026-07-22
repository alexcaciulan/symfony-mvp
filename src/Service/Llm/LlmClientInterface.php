<?php

namespace App\Service\Llm;

use App\DTO\Llm\LlmResponse;

/**
 * Provider-neutral contract for LLM completion calls. Implementations:
 *   - {@see AnthropicApiClient} (Pas 2.5.6, default for MVP)
 *   - Future: `OllamaApiClient` (V2 self-hosted per ANALIZA-FLUXURI:448),
 *     `OpenAiApiClient` (alternative if Anthropic GDPR posture changes).
 *
 * Callers depend exclusively on this interface — they never reference a
 * concrete provider client. Switching providers is therefore a
 * services.yaml binding change, with zero modifications to caller code or
 * caller tests.
 *
 * Implementations MUST throw {@see LlmException} on any failure (auth,
 * rate-limit-exhausted-after-retries, malformed response, timeout).
 * Returning a partial / placeholder response on failure is forbidden —
 * callers cannot distinguish "AI gave a vague answer" from "AI failed".
 */
interface LlmClientInterface
{
    /**
     * Sends a structured chat-style prompt to the LLM and returns the response.
     *
     * @param array<int, array{role: 'system'|'user'|'assistant', content: string|array<int, array<string, mixed>>}> $messages
     *        Chat-style message list. Most callers pass a single user message;
     *        Pas 2.5.8 may supply a system prompt + user message + structured
     *        content blocks for vision input.
     * @param int $maxTokens Hard cap on output token count. Defaults to 2048,
     *                       enough for the structured-JSON extraction prompts
     *                       used by Pas 2.5.7 / 2.5.8.
     * @param array<int, array<string, mixed>>|null $documentParts
     *        Optional list of provider-formatted image / PDF parts for vision
     *        calls. null = text-only completion. Implementations are
     *        responsible for splicing these into the request body in their
     *        provider-specific shape.
     * @param bool $cacheSystemPrompt asks the provider to cache the stable
     *        prefix of the request (the system blocks) so a batch of documents
     *        sharing the same instructions pays for them once. Implementations
     *        without a prompt cache ignore it. Only pass true when the system
     *        blocks really are byte-identical across calls: a prefix that
     *        changes per call costs the cache-write premium and never gets read.
     * @param array<string, mixed>|null $outputSchema JSON Schema the response
     *        must conform to. Implementations that support constrained decoding
     *        enforce it; the rest ignore it, so callers must still validate what
     *        they parse.
     *

     * @note GDPR — callers MUST mask personal data (CNP, IBAN, address) in the
     *       message contents BEFORE invoking this method. The request body
     *       leaves the server boundary; once at Anthropic (or any other LLM
     *       provider) the data is no longer under the application's control.
     *       Use {@see App\Util\PiiMasker::buildCnpMap()} and
     *       {@see App\Util\PiiMasker::maskCnp()} on the OCR text before
     *       constructing the messages, and re-substitute via
     *       {@see App\Util\PiiMasker::restoreCnp()} on the response.
     *
     * @throws LlmException
     */
    public function complete(
        array $messages,
        int $maxTokens = 2048,
        ?array $documentParts = null,
        bool $cacheSystemPrompt = false,
        ?array $outputSchema = null,
    ): LlmResponse;
}
