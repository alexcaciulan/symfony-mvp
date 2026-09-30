<?php

declare(strict_types=1);

namespace App\Tests\Form\Wizard;

use App\DTO\Wizard\Step1CreditorData;
use App\Enum\PersonType;
use App\Form\Wizard\Step1CreditorType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Validation + view tests for the wizard step 1 form.
 *
 * Two concerns covered here: (1) bind/validate round-trip via real Symfony
 * Validator (the DTO carries the constraints; we exercise the form just as
 * the controller will at Pas 3.2); (2) the `data-auto-filled` attribute is
 * applied in finishView() to the right fields and only to the right fields.
 */
final class Step1CreditorTypeTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(FormFactoryInterface::class);
    }

    /**
     * Only the library picker selects an existing creditor. An id left in the
     * hidden field by an earlier pick must not survive an empty picker, or the
     * case would be filed under that creditor instead of the one typed in.
     */
    public function testCreditorIdWithoutALibraryPickIsIgnored(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'creditorId' => '42',
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertFalse($form->isValid());
        $dto = $form->getData();
        self::assertInstanceOf(Step1CreditorData::class, $dto);
        self::assertNull($dto->creditorId);
    }

    public function testSubmitWithFullManualFillIsValid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PJ',
            'name' => 'SC Foo SRL',
            'cui' => '15193236',
            'onrcNumber' => 'J40/1234/2018',
            'address' => 'Str. Test 1, București',
            'email' => 'foo@example.com',
            'iban' => 'RO49RNCB0082004480010001',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $dto = $form->getData();
        self::assertSame(PersonType::PJ, $dto->personType);
        self::assertSame('SC Foo SRL', $dto->name);
    }

    public function testManualPjWithoutOnrcIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PJ',
            'name' => 'SC Foo SRL',
            'cui' => '15193236',
            // onrcNumber missing — required for PJ via Step1CreditorData callback
            'address' => 'Str. Test 1, București',
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('onrcNumber')->getErrors()->count());
    }

    public function testPersonTypePfIsRejectedForCreditor(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PF',
            'name' => 'Ion Popescu',
            'personalId' => '1980715221232',
            'address' => 'Str. Test 1, București',
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('personType')->getErrors()->count());
    }

    public function testSubmitWithoutIdOrManualIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([]);

        self::assertFalse($form->isValid());
    }

    public function testSubmitWithInvalidCuiIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'creditorId' => '42',
            'cui' => '12345678',
        ]);

        self::assertFalse($form->isValid());
    }

    public function testAutoFilledFieldsCarryDataAttribute(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'SC Foo SRL',
            address: 'Str. Test 1',
            autoFilled: ['name', 'address'],
        );

        $form = $this->buildForm($dto);
        $view = $form->createView();

        self::assertSame('true', $view->children['name']->vars['attr']['data-auto-filled'] ?? null);
        self::assertSame('true', $view->children['address']->vars['attr']['data-auto-filled'] ?? null);
        self::assertArrayNotHasKey('data-auto-filled', $view->children['iban']->vars['attr']);
    }

    public function testUnknownAutoFilledFieldIsIgnored(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'SC Foo SRL',
            address: 'Str. Test 1',
            autoFilled: ['nonexistentField'],
        );

        $form = $this->buildForm($dto);

        // Just rendering the view must not crash.
        $view = $form->createView();
        self::assertArrayNotHasKey('nonexistentField', $view->children);
    }

    public function testCsrfTokenIdIsConfigured(): void
    {
        $resolver = new OptionsResolver();
        (new Step1CreditorType())->configureOptions($resolver);
        $options = $resolver->resolve();

        self::assertTrue($options['csrf_protection']);
        self::assertSame('wizard_step1_creditor', $options['csrf_token_id']);
        self::assertSame('_token', $options['csrf_field_name']);
    }

    public function testPersonTypePjClearsPersonalIdOnSubmit(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PJ',
            'name' => 'SC Foo SRL',
            'cui' => 'RO15193236',
            'onrcNumber' => 'J40/1234/2018',
            'address' => 'Str. Test 1, București',
            'personalId' => '1980715221232', // stale value from a previous PF selection
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $dto = $form->getData();
        self::assertSame(PersonType::PJ, $dto->personType);
        self::assertNull($dto->personalId, 'personalId (CNP) must be cleared for PJ');
        self::assertSame('RO15193236', $dto->cui);
    }

    private function buildForm(?Step1CreditorData $data = null): FormInterface
    {
        return $this->factory->create(Step1CreditorType::class, $data, ['csrf_protection' => false]);
    }
}
