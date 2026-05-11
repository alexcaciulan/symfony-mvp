<?php

declare(strict_types=1);

namespace App\Tests\Form\Wizard;

use App\DTO\Wizard\Step4ConfirmationData;
use App\Form\Wizard\Step4ConfirmationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class Step4ConfirmationTypeTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(FormFactoryInterface::class);
    }

    public function testBothCheckedIsValid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'acceptTerms' => '1',
            'acceptDataAccuracy' => '1',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $dto = $form->getData();
        self::assertInstanceOf(Step4ConfirmationData::class, $dto);
        self::assertTrue($dto->acceptTerms);
        self::assertTrue($dto->acceptDataAccuracy);
    }

    public function testAcceptTermsUncheckedIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'acceptDataAccuracy' => '1',
        ]);

        self::assertFalse($form->isValid());
    }

    public function testAcceptDataAccuracyUncheckedIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'acceptTerms' => '1',
        ]);

        self::assertFalse($form->isValid());
    }

    public function testAcknowledgedWarningsFieldExistsButHasNoConstraintIn31(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'acceptTerms' => '1',
            'acceptDataAccuracy' => '1',
            // acknowledgedWarnings deliberately omitted (false default)
        ]);

        self::assertTrue($form->isValid());
        self::assertFalse($form->getData()->acknowledgedWarnings);
    }

    private function buildForm(): FormInterface
    {
        return $this->factory->create(Step4ConfirmationType::class, null, ['csrf_protection' => false]);
    }
}
