<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * The daily rate of a contractual penalty: required on the contractual branch,
 * above zero and at most 5% a day. Shared by every form that writes the
 * accessory configuration of a case, so the wizard and the case page refuse
 * the same values with the same message. Reads `penaltyType` on the object
 * that owns the property.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class ContractualPenaltyRateRules extends Compound
{
    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\When(
                expression: 'this.penaltyType === enum("App\\\\Enum\\\\PenaltyType::CONTRACTUAL")',
                constraints: [
                    new Assert\NotNull(message: 'wizard.step3.error.penalty_rate_required'),
                    new Assert\Positive(message: 'wizard.step3.error.penalty_rate_positive'),
                    new Assert\LessThanOrEqual(value: 5, message: 'wizard.step3.error.penalty_rate_too_high'),
                ],
            ),
        ];
    }
}
