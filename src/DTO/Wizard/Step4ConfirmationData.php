<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Wizard step 4 — Confirmare.
 *
 * Two unconditional acknowledgements (terms + data accuracy) plus a third
 * checkbox `acknowledgedWarnings` only required when `OpAdmissibilityValidator`
 * reports WARNING-level issues. The controller activates the `with_warnings`
 * validation group on submit when warnings are present, otherwise only the
 * `Default` group runs.
 */
class Step4ConfirmationData
{
    public function __construct(
        #[Assert\IsTrue(message: 'wizard.step4.error.must_accept_terms')]
        public bool $acceptTerms = false,
        #[Assert\IsTrue(message: 'wizard.step4.error.must_accept_data_accuracy')]
        public bool $acceptDataAccuracy = false,
        #[Assert\IsTrue(
            message: 'wizard.step4.error.must_acknowledge_warnings',
            groups: ['with_warnings'],
        )]
        public bool $acknowledgedWarnings = false,
    ) {}
}
