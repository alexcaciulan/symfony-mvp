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
            'onrcNumber' => 'J40/8765/2019',
            'address' => 'Bd. Test 2',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    public function testSubmitPjMissingOnrcIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PJ',
            'name' => 'SC Bar SRL',
            'cui' => '14186770',
            // onrcNumber missing — required for PJ via Step2DebtorEntry callback
            'address' => 'Bd. Test 2',
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('onrcNumber')->getErrors()->count());
    }

    public function testSubmitPfMissingCnpIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PF',
            'name' => 'Ion Popescu',
            // personalId missing — required for PF
            'address' => 'Str. Test 1',
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('personalId')->getErrors()->count());
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

    public function testPersonTypePfClearsCuiOnrcAndAdministratorOnSubmit(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PF',
            'name' => 'Ion Popescu',
            'cui' => 'RO14186770',        // stale from previous PJ selection
            'onrcNumber' => 'J40/8765/2019', // stale
            'administrator' => 'Ion Popescu', // stale (PF has no administrator)
            'personalId' => '1980715221232',
            'address' => 'Bd. Test 2',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $dto = $form->getData();
        self::assertSame(PersonType::PF, $dto->personType);
        self::assertNull($dto->cui, 'CUI must be cleared for PF');
        self::assertNull($dto->onrcNumber, 'onrcNumber must be cleared for PF');
        self::assertNull($dto->administrator, 'administrator must be cleared for PF');
        self::assertNull($dto->anafStatus, 'anafStatus must be cleared for PF (ANAF only applies to PJ)');
        self::assertNull($dto->anafCheckedAt, 'anafCheckedAt must be cleared for PF');
        self::assertSame('1980715221232', $dto->personalId);
    }

    public function testPersonTypePjClearsPersonalIdOnSubmit(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PJ',
            'name' => 'SC Bar SRL',
            'cui' => '14186770',
            'onrcNumber' => 'J40/8765/2019',
            'address' => 'Bd. Test 2',
            'personalId' => '1980715221232', // stale from previous PF selection
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $dto = $form->getData();
        self::assertSame(PersonType::PJ, $dto->personType);
        self::assertNull($dto->personalId, 'personalId (CNP) must be cleared for PJ');
        self::assertSame('14186770', $dto->cui);
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
