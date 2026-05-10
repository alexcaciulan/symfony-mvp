<?php

namespace App\Enum;

/**
 * Provider-neutral classification for why an LLM call ended. Mapped from
 * provider-specific stop reasons (Anthropic `stop_reason`, OpenAI
 * `finish_reason`, Ollama equivalent) inside each {@see App\Service\Llm\LlmClientInterface}
 * implementation, so callers depend only on this neutral vocabulary.
 *
 * Semantics:
 *   - COMPLETED     — the model finished its response naturally (Anthropic 'end_turn').
 *   - MAX_TOKENS    — output truncated at the requested max_tokens cap.
 *   - STOP_SEQUENCE — output stopped at a configured stop sequence.
 *   - OTHER         — anything else: tool_use signals, refusals, content filter, etc.
 *                     Caller should inspect raw response if it cares about the distinction.
 */
enum LlmFinishReason: string
{
    case COMPLETED = 'COMPLETED';
    case MAX_TOKENS = 'MAX_TOKENS';
    case STOP_SEQUENCE = 'STOP_SEQUENCE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return 'enum.llm_finish_reason.' . $this->value;
    }
}
