<?php

namespace App\DTO\Extraction;

use App\Enum\LegalGroundCategory;

final readonly class ClaimExtraction
{
    /** @param array<string, float> $confidencePerField field name → confidence score 0..1 */
    public function __construct(
        public ?float $amount = null,
        public ?string $currency = null,
        public ?\DateTimeImmutable $dueDate = null,
        public ?LegalGroundCategory $legalGround = null,
        public ?string $description = null,
        public array $confidencePerField = [],
    ) {}
}
