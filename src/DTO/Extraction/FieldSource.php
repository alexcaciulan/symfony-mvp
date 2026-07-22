<?php

declare(strict_types=1);

namespace App\DTO\Extraction;

use App\Enum\DocumentType;

/**
 * One document's contribution to one section (creditor, debtor, claim), as read
 * back from its extraction payload.
 *
 * Deliberately flat: the aggregation reasons about (field, value, confidence)
 * triples plus where they came from, and nothing else. Anything richer would
 * have to be kept in step with the payload shape for no gain.
 */
final readonly class FieldSource
{
    /**
     * @param int $documentId the persisted id where there is one; the caller
     *        substitutes the position in the loaded set otherwise, so ordering
     *        stays total and the tie-break stays deterministic
     * @param array<string, mixed> $values field name to raw value, nulls dropped
     * @param array<string, float> $confidence field name to score 0..1
     */
    public function __construct(
        public int $documentId,
        public ?DocumentType $documentType = null,
        public array $values = [],
        public array $confidence = [],
    ) {}

    public function confidenceOf(string $field): float
    {
        return $this->confidence[$field] ?? 0.0;
    }

    /**
     * The value of a field this source states well enough to be used.
     */
    public function trustedValue(string $field, float $threshold): mixed
    {
        if (!array_key_exists($field, $this->values) || $this->values[$field] === null) {
            return null;
        }

        return $this->confidenceOf($field) >= $threshold ? $this->values[$field] : null;
    }
}
