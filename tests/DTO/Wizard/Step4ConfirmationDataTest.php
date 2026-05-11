<?php

declare(strict_types=1);

namespace App\Tests\DTO\Wizard;

use App\DTO\Wizard\Step4ConfirmationData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class Step4ConfirmationDataTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testBothAcceptTrueIsValid(): void
    {
        $dto = new Step4ConfirmationData(
            acceptTerms: true,
            acceptDataAccuracy: true,
        );

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations);
    }

    public function testAcceptTermsFalseIsInvalid(): void
    {
        $dto = new Step4ConfirmationData(
            acceptTerms: false,
            acceptDataAccuracy: true,
        );

        $violations = $this->validator->validate($dto);

        self::assertSame(1, $violations->count());
        self::assertSame('wizard.step4.error.must_accept_terms', $violations[0]->getMessageTemplate());
    }

    public function testAcceptDataAccuracyFalseIsInvalid(): void
    {
        $dto = new Step4ConfirmationData(
            acceptTerms: true,
            acceptDataAccuracy: false,
        );

        $violations = $this->validator->validate($dto);

        self::assertSame(1, $violations->count());
        self::assertSame('wizard.step4.error.must_accept_data_accuracy', $violations[0]->getMessageTemplate());
    }

    public function testAcknowledgedWarningsNotValidatedIn31(): void
    {
        // In Pas 3.1 the `acknowledgedWarnings` field has no constraint —
        // Pas 3.2 controller orchestrates it conditionally via validation_groups
        // when OpAdmissibilityValidator reports WARNINGs.
        $dto = new Step4ConfirmationData(
            acceptTerms: true,
            acceptDataAccuracy: true,
            acknowledgedWarnings: false,
        );

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations);
    }
}
