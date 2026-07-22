<?php

declare(strict_types=1);

namespace App\Service\Extraction\Prompt;

use App\Enum\DocumentType;

/**
 * Resolves the prompt for a document type, mirroring the tagged-iterator setup
 * the extraction strategies already use (`app.extraction_prompt`).
 *
 * Selection is by declared type only. A type nobody claims, or a document
 * whose type was never declared, falls back to the generic prompt, which
 * classifies and extracts in one pass.
 */
final class ExtractionPromptRegistry
{
    /** @var list<ExtractionPromptInterface> */
    private array $sortedPrompts;

    /**
     * @param iterable<ExtractionPromptInterface> $prompts tagged `app.extraction_prompt`
     */
    public function __construct(
        iterable $prompts,
        private readonly GenericDocumentPrompt $fallback,
    ) {
        $list = [];
        foreach ($prompts as $prompt) {
            $list[] = $prompt;
        }
        usort(
            $list,
            static fn (ExtractionPromptInterface $a, ExtractionPromptInterface $b): int => $b->priority() <=> $a->priority(),
        );
        $this->sortedPrompts = $list;
    }

    /**
     * The prompt for a declared type. `ALT_DOCUMENT` is what the wizard stores
     * when the lawyer did not say what the file is, so it resolves to the
     * generic prompt and the model gets asked to classify.
     */
    public function forType(?DocumentType $type): ExtractionPromptInterface
    {
        if ($type === null || $type === DocumentType::ALT_DOCUMENT) {
            return $this->fallback;
        }

        foreach ($this->sortedPrompts as $prompt) {
            if ($prompt->supports($type)) {
                return $prompt;
            }
        }

        return $this->fallback;
    }
}
