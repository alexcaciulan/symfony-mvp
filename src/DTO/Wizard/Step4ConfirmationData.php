<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Wizard step 4 — Confirmare.
 *
 * Two unconditional acknowledgements (terms + data accuracy) plus a third
 * checkbox `acknowledgedWarnings` that is only required when
 * `OpAdmissibilityValidator` reports WARNING-level issues. The conditional
 * constraint is wired in Pas 3.2 controller via `validation_groups` callback
 * — here we only declare the property with a `false` default.
 */
class Step4ConfirmationData
{
    public function __construct(
        #[Assert\IsTrue(message: 'wizard.step4.error.must_accept_terms')]
        public bool $acceptTerms = false,
        #[Assert\IsTrue(message: 'wizard.step4.error.must_accept_data_accuracy')]
        public bool $acceptDataAccuracy = false,
        public bool $acknowledgedWarnings = false,
    ) {}
}
