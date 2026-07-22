<?php

declare(strict_types=1);

namespace App\DTO\Extraction;

use App\Enum\DocumentType;

/**
 * One of the values a conflict is between, with where it was read from.
 *
 * The provenance is the point. A lawyer choosing between two CUIs is not
 * choosing between two numbers, they are choosing between the contract and the
 * invoice, and they must be able to say afterwards which document they relied
 * on and why.
 */
final readonly class ConflictOption
{
    public function __construct(
        public mixed $value,
        public ?int $documentId = null,
        public ?DocumentType $documentType = null,
        public float $confidence = 0.0,
    ) {}

    /**
     * The value as the lawyer has to read it when choosing between documents.
     */
    public function displayValue(): string
    {
        if ($this->value instanceof \DateTimeInterface) {
            return $this->value->format('d.m.Y');
        }
        if (is_float($this->value) || is_int($this->value)) {
            $number = (float) $this->value;

            return number_format($number, fmod($number, 1.0) === 0.0 ? 0 : 2, ',', '.');
        }
        if (is_bool($this->value)) {
            return $this->value ? '1' : '0';
        }

        return is_scalar($this->value) ? (string) $this->value : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value instanceof \DateTimeInterface
                ? $this->value->format('Y-m-d')
                : $this->value,
            'documentId' => $this->documentId,
            'documentType' => $this->documentType?->value,
            'confidence' => $this->confidence,
        ];
    }
}
