<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * The share of the sum the contractual penalties may not exceed, as the
 * contract states it. Optional, and only read on the contractual branch.
 * Shared by the wizard and the case page.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class ContractualPenaltyCapRules extends Compound
{
    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\When(
                expression: 'this.penaltyType === enum("App\\\\Enum\\\\PenaltyType::CONTRACTUAL")',
                constraints: [
                    new Assert\Positive(message: 'wizard.step3.error.penalty_cap_positive'),
                    new Assert\LessThanOrEqual(value: 100, message: 'wizard.step3.error.penalty_cap_too_high'),
                ],
            ),
        ];
    }
}
