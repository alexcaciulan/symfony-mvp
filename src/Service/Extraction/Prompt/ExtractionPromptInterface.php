<?php

declare(strict_types=1);

namespace App\Service\Extraction\Prompt;

use App\Enum\DocumentType;

/**
 * One extraction prompt, selected by document type.
 *
 * Implementations are auto-collected through the `app.extraction_prompt` tag,
 * the same mechanism the extraction strategies use, and resolved by
 * {@see ExtractionPromptRegistry}.
 *
 * The split between {@see self::systemPrompt()} and
 * {@see self::userInstructions()} is a cost decision, not a stylistic one:
 * everything stable lives in the system block so it is byte-identical across
 * every document in a batch and can be served from the provider's prompt
 * cache, while the per-type part rides in the user turn after the document.
 * Any per-type text that leaks into the system block breaks the shared prefix
 * and every document in the batch pays full input price again.
 */
interface ExtractionPromptInterface
{
    /**
     * Stable identifier, used in logs and the audit trail so an extraction can
     * be traced back to the instructions that produced it.
     */
    public function key(): string;

    public function supports(DocumentType $type): bool;

    /**
     * Higher wins when several prompts accept the same type.
     */
    public function priority(): int;

    /**
     * Stable prefix. MUST be byte-identical across implementations, which is
     * why every implementation delegates to {@see SharedPromptFragments}.
     */
    public function systemPrompt(): string;

    /**
     * Type-specific instructions, appended after the document block.
     */
    public function userInstructions(): string;

    /**
     * JSON Schema handed to the provider's structured-output mode. Null means
     * the prompt does not constrain the response shape and the caller falls
     * back to parsing free-form JSON.
     *
     * @return array<string, mixed>|null
     */
    public function outputSchema(): ?array;

    /**
     * Output budget. A ledger-style document needs several times what a
     * contract does, and a single shared value either truncates the former or
     * buys timeout risk for the latter.
     */
    public function maxTokens(): int;
}
