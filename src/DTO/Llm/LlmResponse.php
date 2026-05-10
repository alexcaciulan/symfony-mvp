<?php

namespace App\DTO\Llm;

use App\Enum\LlmFinishReason;

/**
 * Provider-neutral DTO for a completed LLM call. Implementations of
 * {@see App\Service\Llm\LlmClientInterface} map their provider-specific
 * response shape into this DTO so callers (Pas 2.5.7 OcrTextStrategy,
 * Pas 2.5.8 AiVisionStrategy) don't need to know whether the underlying
 * provider was Anthropic, OpenAI, Ollama, etc.
 *
 *   - `content`      — raw text response from the model. Callers parse this
 *                      (typically as JSON) according to the prompt contract.
 *   - `tokensIn`     — input token count, useful for cost telemetry / audit.
 *   - `tokensOut`    — output token count.
 *   - `finishReason` — neutral classification (see {@see LlmFinishReason}).
 */
final readonly class LlmResponse
{
    public function __construct(
        public string $content,
        public int $tokensIn,
        public int $tokensOut,
        public LlmFinishReason $finishReason,
    ) {}
}
