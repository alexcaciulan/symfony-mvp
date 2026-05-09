<?php

namespace App\DTO\Extraction;

use App\Enum\PersonType;

final readonly class DebtorExtraction
{
    /** @param array<string, float> $confidencePerField field name → confidence score 0..1 */
    public function __construct(
        public ?PersonType $personType = null,
        public ?string $name = null,
        public ?string $cui = null,
        public ?string $personalId = null,
        public ?string $address = null,
        public array $confidencePerField = [],
    ) {}
}
