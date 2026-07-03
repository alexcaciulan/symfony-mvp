<?php

namespace App\DTO\Extraction;

use App\Enum\LegalGroundCategory;
use App\Enum\PenaltyType;

final readonly class ClaimExtraction
{
    /**
     * @param ?PenaltyType         $penaltyType            CONTRACTUAL only when the source carries an explicit penalty
     *                                                     clause with a daily rate; null otherwise (the wizard default applies)
     * @param ?float               $contractualPenaltyRate daily penalty rate as a percentage (e.g. 0.1 for "0,1%/zi"),
     *                                                     paired with PenaltyType::CONTRACTUAL
     * @param array<string, float> $confidencePerField     field name → confidence score 0..1
     */
    public function __construct(
        public ?float $amount = null,
        public ?string $currency = null,
        public ?\DateTimeImmutable $dueDate = null,
        public ?LegalGroundCategory $legalGround = null,
        public ?string $description = null,
        public ?string $invoiceNumber = null,
        public ?\DateTimeImmutable $invoiceDate = null,
        public ?string $contractNumber = null,
        public ?\DateTimeImmutable $contractDate = null,
        public ?string $contractReference = null,
        public ?PenaltyType $penaltyType = null,
        public ?float $contractualPenaltyRate = null,
        public array $confidencePerField = [],
    ) {}
}
