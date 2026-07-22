<?php

declare(strict_types=1);

namespace App\Service\Extraction\Prompt;

/**
 * Holds the parts no prompt may vary: the system block and the response shape.
 *
 * Implementations override only the type they accept, the instructions that go
 * after the document, and their output budget. Anything else would fork the
 * cached prefix or the payload shape the wizard reads back.
 */
abstract class AbstractExtractionPrompt implements ExtractionPromptInterface
{
    /**
     * Output budget for documents that carry a handful of fields. A contract, a
     * demand letter or a bank statement produces one party pair and one claim,
     * which fits well under this.
     */
    protected const COMPACT_MAX_TOKENS = 4096;

    /**
     * Budget for documents that enumerate positions (an invoice with many
     * lines, a balance confirmation listing every open invoice). These run
     * several times longer than a contract, and truncation here is expensive:
     * the call is already paid for when the output is cut.
     */
    protected const LEDGER_MAX_TOKENS = 16000;

    public function systemPrompt(): string
    {
        return SharedPromptFragments::systemPrompt();
    }

    public function outputSchema(): ?array
    {
        return SharedPromptFragments::responseSchema(withClassification: $this->classifies());
    }

    public function priority(): int
    {
        return 100;
    }

    public function maxTokens(): int
    {
        return self::COMPACT_MAX_TOKENS;
    }

    /**
     * Whether the response carries a classification object. Only the pass over
     * a document of unknown type needs one; when the type is already known,
     * asking again would invite the model to contradict the lawyer.
     */
    protected function classifies(): bool
    {
        return false;
    }
}
