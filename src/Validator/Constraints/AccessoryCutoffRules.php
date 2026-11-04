<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * The date the accessories are computed up to instead of today. A future date
 * would claim interest not yet owed, and a date on or before the due date
 * leaves nothing to accrue. Reads `dueDate` on the object that owns the
 * property. Shared by the wizard and the case page.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class AccessoryCutoffRules extends Compound
{
    /** @param array<string, mixed> $options */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\LessThanOrEqual(value: 'today', message: 'wizard.step3.error.accessory_cutoff_future'),
            new Assert\Expression(
                expression: 'value === null or this.dueDate === null or value > this.dueDate',
                message: 'wizard.step3.error.accessory_cutoff_before_due',
            ),
        ];
    }
}
