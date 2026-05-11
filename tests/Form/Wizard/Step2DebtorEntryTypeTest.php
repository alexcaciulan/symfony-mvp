<?php

declare(strict_types=1);

namespace App\Tests\Form\Wizard;

use App\DTO\Wizard\Step2DebtorEntry;
use App\Enum\PersonType;
use App\Form\Wizard\Step2DebtorEntryType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

final class Step2DebtorEntryTypeTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(FormFactoryInterface::class);
    }

    public function testSubmitValidPjDebtorIsValid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PJ',
            'name' => 'SC Bar SRL',
            'cui' => '14186770',
            'address' => 'Bd. Test 2',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    public function testSubmitMissingRequiredIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PJ',
            // missing name + address
        ]);

        self::assertFalse($form->isValid());
    }

    public function testAutoFilledFieldsCarryDataAttribute(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
            cui: '14186770',
            address: 'Bd. Test 2',
            autoFilled: ['name', 'cui'],
        );

        $form = $this->buildForm($dto);
        $view = $form->createView();

        self::assertSame('true', $view->children['name']->vars['attr']['data-auto-filled'] ?? null);
        self::assertSame('true', $view->children['cui']->vars['attr']['data-auto-filled'] ?? null);
        self::assertArrayNotHasKey('data-auto-filled', $view->children['address']->vars['attr']);
    }

    private function buildForm(?Step2DebtorEntry $data = null): FormInterface
    {
        return $this->factory->create(Step2DebtorEntryType::class, $data, ['csrf_protection' => false]);
    }
}
