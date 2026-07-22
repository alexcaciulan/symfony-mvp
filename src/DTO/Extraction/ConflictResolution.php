<?php

declare(strict_types=1);

namespace App\DTO\Extraction;

use App\Enum\ConflictScope;
use App\Enum\DocumentType;

/**
 * What the lawyer decided about one disagreement between documents.
 *
 * Carried in the wizard session bag and applied with priority over anything the
 * aggregation ranks. It is also the record of the decision: a value in a filing
 * has to be traceable to the file it was read from, and a value the lawyer typed
 * themselves has to be distinguishable from one a document states.
 */
final readonly class ConflictResolution
{
    /**
     * @param ?int $optionIndex position in the conflict's option list; null when
     *        the lawyer typed the value instead of choosing a document
     * @param string $optionSignature the chosen option as it read when it was
     *        chosen, so a stale pick over a changed document set is dropped
     *        rather than silently pointing at another value
     */
    public function __construct(
        public string $conflictKey,
        public ConflictScope $scope,
        public ?string $field,
        public ?string $entityKey,
        public mixed $value,
        public ?int $optionIndex = null,
        public ?int $documentId = null,
        public ?DocumentType $documentType = null,
        public string $optionSignature = '',
    ) {}

    public function isManual(): bool
    {
        return $this->optionIndex === null;
    }

    /**
     * The value as the panel has to show it back, so re-entering the step shows
     * what was decided rather than an empty field.
     */
    public function displayValue(): string
    {
        return (new ConflictOption(value: $this->value))->displayValue();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'conflictKey' => $this->conflictKey,
            'scope' => $this->scope->value,
            'field' => $this->field,
            'entityKey' => $this->entityKey,
            'value' => $this->value instanceof \DateTimeInterface
                ? $this->value->format('Y-m-d')
                : $this->value,
            'optionIndex' => $this->optionIndex,
            'documentId' => $this->documentId,
            'documentType' => $this->documentType?->value,
            'manual' => $this->isManual(),
        ];
    }
}
