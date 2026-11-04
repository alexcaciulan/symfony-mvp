<?php

declare(strict_types=1);

namespace App\DTO\Case;

use App\DTO\Wizard\Step3ClaimData;
use App\Entity\LegalCase;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The recovery costs the payment order petition asks for, as the case page
 * edits them. Same rules and messages as the wizard's claim step.
 */
class LegalCostsInput
{
    public function __construct(
        #[Assert\PositiveOrZero(message: 'wizard.step3.error.legal_costs_fixed_positive')]
        public ?float $legalCostsFixed = null,
        #[Assert\Choice(choices: Step3ClaimData::SUPPORTED_CURRENCIES, message: 'wizard.step3.error.invalid_currency')]
        public ?string $legalCostsCurrency = 'EUR',
        #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'wizard.step3.error.legal_costs_percent_range')]
        public ?float $legalCostsSuccessPercent = null,
    ) {}

    public static function fromCase(LegalCase $case): self
    {
        $fixed = $case->getLegalCostsFixed();
        $percent = $case->getLegalCostsSuccessPercent();

        return new self(
            legalCostsFixed: $fixed !== null ? (float) $fixed : null,
            legalCostsCurrency: $case->getLegalCostsCurrency(),
            legalCostsSuccessPercent: $percent !== null ? (float) $percent : null,
        );
    }
}
