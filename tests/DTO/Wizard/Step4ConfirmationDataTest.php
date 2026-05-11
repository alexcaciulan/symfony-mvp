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

    public function testAcknowledgedWarningsNotValidatedInDefaultGroup(): void
    {
        $dto = new Step4ConfirmationData(
            acceptTerms: true,
            acceptDataAccuracy: true,
            acknowledgedWarnings: false,
        );

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations);
    }

    public function testAcknowledgedWarningsRequiredInWithWarningsGroup(): void
    {
        $dto = new Step4ConfirmationData(
            acceptTerms: true,
            acceptDataAccuracy: true,
            acknowledgedWarnings: false,
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'with_warnings']);

        self::assertSame(1, $violations->count());
        self::assertSame(
            'wizard.step4.error.must_acknowledge_warnings',
            $violations[0]->getMessageTemplate(),
        );
    }

    public function testAcknowledgedWarningsCheckedSatisfiesWithWarningsGroup(): void
    {
        $dto = new Step4ConfirmationData(
            acceptTerms: true,
            acceptDataAccuracy: true,
            acknowledgedWarnings: true,
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'with_warnings']);

        self::assertCount(0, $violations);
    }
}
