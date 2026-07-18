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
            'bpiVerifiedToday' => '1',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
    }

    /**
     * The BPI attestation is mandatory: without it the entry must not validate,
     * and the violation has to surface on the checkbox the lawyer sees rather
     * than on the widget-less `insolvencyCheckedAt` property.
     */
    public function testSubmitWithoutBpiConfirmationIsInvalid(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PJ',
            'name' => 'SC Bar SRL',
            'cui' => '14186770',
            'onrcNumber' => 'J40/8765/2019',
            'address' => 'Bd. Test 2',
            // bpiVerifiedToday unticked
        ]);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, $form->get('bpiVerifiedToday')->getErrors()->count());
        self::assertNull($form->getData()->insolvencyCheckedAt);
    }

    public function testTickingBpiConfirmationStampsTheTimestamp(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PJ',
            'name' => 'SC Bar SRL',
            'cui' => '14186770',
            'onrcNumber' => 'J40/8765/2019',
            'address' => 'Bd. Test 2',
            'bpiVerifiedToday' => '1',
        ]);

        self::assertInstanceOf(\DateTimeImmutable::class, $form->getData()->insolvencyCheckedAt);
    }

    /**
     * The attestation states the debtor is not in insolvency right now, so
     * withdrawing it must clear the timestamp rather than leave the earlier
     * confirmation standing.
     */
    public function testUntickingBpiConfirmationClearsAnExistingTimestamp(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
            cui: '14186770',
            onrcNumber: 'J40/8765/2019',
            address: 'Bd. Test 2',
            insolvencyCheckedAt: new \DateTimeImmutable('-1 day'),
        );

        $form = $this->buildForm($dto);
        $form->submit([
            'personType' => 'PJ',
            'name' => 'SC Bar SRL',
            'cui' => '14186770',
            'onrcNumber' => 'J40/8765/2019',
            'address' => 'Bd. Test 2',
            // bpiVerifiedToday unticked
        ]);

        self::assertFalse($form->isValid());
        self::assertNull($form->getData()->insolvencyCheckedAt);
    }

    /**
     * The checkbox is unmapped, so it renders blank on back-navigation unless
     * the form re-ticks it from the DTO — otherwise returning to step 2 would
     * block a debtor the lawyer already verified.
     */
    public function testExistingAttestationReticksTheCheckbox(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
            cui: '14186770',
            address: 'Bd. Test 2',
            insolvencyCheckedAt: new \DateTimeImmutable(),
        );

        $form = $this->buildForm($dto);

        self::assertTrue($form->get('bpiVerifiedToday')->getData());
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
            'bpiVerifiedToday' => '1',        // stale — BPI does not apply to PF
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $dto = $form->getData();
        self::assertSame(PersonType::PF, $dto->personType);
        self::assertNull($dto->cui, 'CUI must be cleared for PF');
        self::assertNull($dto->onrcNumber, 'onrcNumber must be cleared for PF');
        self::assertNull($dto->administrator, 'administrator must be cleared for PF');
        self::assertNull($dto->anafStatus, 'anafStatus must be cleared for PF (ANAF only applies to PJ)');
        self::assertNull($dto->anafCheckedAt, 'anafCheckedAt must be cleared for PF');
        self::assertNull($dto->insolvencyCheckedAt, 'BPI attestation must be dropped for PF (Legea 85/2014 covers PJ)');
        self::assertSame('1980715221232', $dto->personalId);
    }

    /**
     * A PF debtor validates without the BPI attestation: BPI (Legea 85/2014)
     * covers PJ searchable by CUI, so the mandatory tick is PJ-only.
     */
    public function testPfDebtorIsValidWithoutBpiAttestation(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PF',
            'name' => 'Ion Popescu',
            'personalId' => '1980715221232',
            'address' => 'Bd. Test 2',
            // bpiVerifiedToday unticked
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertNull($form->getData()->insolvencyCheckedAt);
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
            'bpiVerifiedToday' => '1',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $dto = $form->getData();
        self::assertSame(PersonType::PJ, $dto->personType);
        self::assertNull($dto->personalId, 'personalId (CNP) must be cleared for PJ');
        self::assertSame('14186770', $dto->cui);
    }

    public function testSubmitBindsCountyAndLocality(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PJ',
            'name' => 'SC Bar SRL',
            'cui' => '14186770',
            'onrcNumber' => 'J40/8765/2019',
            'address' => 'Bd. Test 2',
            'addressCounty' => 'Cluj',
            'addressLocality' => 'Cluj-Napoca',
            'bpiVerifiedToday' => '1',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $dto = $form->getData();
        self::assertSame('Cluj', $dto->addressCounty);
        self::assertSame('Cluj-Napoca', $dto->addressLocality);
    }

    public function testCountyAndLocalityAreOptional(): void
    {
        $form = $this->buildForm();
        $form->submit([
            'personType' => 'PJ',
            'name' => 'SC Bar SRL',
            'cui' => '14186770',
            'onrcNumber' => 'J40/8765/2019',
            'address' => 'Bd. Test 2',
            // addressCounty + addressLocality omitted
            'bpiVerifiedToday' => '1',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        $dto = $form->getData();
        self::assertNull($dto->addressCounty);
        self::assertNull($dto->addressLocality);
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
