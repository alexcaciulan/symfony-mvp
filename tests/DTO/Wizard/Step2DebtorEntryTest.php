<?php

declare(strict_types=1);

namespace App\Tests\DTO\Wizard;

use App\DTO\Wizard\Step2DebtorEntry;
use App\Enum\PersonType;
use App\Tests\Shared\ViolationAssertions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class Step2DebtorEntryTest extends KernelTestCase
{
    use ViolationAssertions;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testValidPjDebtorPasses(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
            cui: '14186770',
            onrcNumber: 'J40/8765/2019',
            address: 'Bd. Test 2, Cluj-Napoca',
        );

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testValidPfDebtorPasses(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PF,
            name: 'Ion Popescu',
            personalId: '1980715221232',
            address: 'Str. Test 1, București',
        );

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testMissingPersonTypeIsInvalid(): void
    {
        $dto = new Step2DebtorEntry(
            name: 'SC Bar SRL',
            address: 'Bd. Test 2',
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('wizard.step2.error.person_type_required', $messages);
    }

    public function testMissingNameIsInvalid(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            cui: '14186770',
            onrcNumber: 'J40/8765/2019',
            address: 'Bd. Test 2',
        );

        $violations = $this->validator->validate($dto);

        self::assertViolation($violations, 'name', 'wizard.step2.error.name_required');
    }

    public function testMissingAddressIsInvalid(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
            cui: '14186770',
            onrcNumber: 'J40/8765/2019',
        );

        $violations = $this->validator->validate($dto);

        self::assertViolation($violations, 'address', 'wizard.step2.error.address_required');
    }

    public function testPjMissingCuiProducesViolationOnCuiProperty(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
            onrcNumber: 'J40/8765/2019',
            address: 'Bd. Test 2',
        );

        $violations = $this->validator->validate($dto);

        self::assertViolation($violations, 'cui', 'wizard.step2.error.cui_required');
    }

    public function testPjMissingOnrcProducesViolationOnOnrcProperty(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
            cui: '14186770',
            address: 'Bd. Test 2',
        );

        $violations = $this->validator->validate($dto);

        self::assertViolation($violations, 'onrcNumber', 'wizard.step2.error.onrc_required');
    }

    public function testPfMissingCnpProducesViolationOnPersonalIdProperty(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PF,
            name: 'Ion Popescu',
            address: 'Str. Test 1',
        );

        $violations = $this->validator->validate($dto);

        self::assertViolation($violations, 'personalId', 'wizard.step2.error.cnp_required');
    }

    public function testPjDoesNotRequireCnp(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
            cui: '14186770',
            onrcNumber: 'J40/8765/2019',
            address: 'Bd. Test 2',
        );

        $violations = $this->validator->validate($dto);

        self::assertNoViolation($violations, 'personalId');
    }

    public function testPfDoesNotRequireCuiOrOnrc(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PF,
            name: 'Ion Popescu',
            personalId: '1980715221232',
            address: 'Str. Test 1',
        );

        $violations = $this->validator->validate($dto);

        self::assertNoViolation($violations, 'cui');
        self::assertNoViolation($violations, 'onrcNumber');
    }

    public function testInvalidCuiIsRejected(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
            cui: '99999999',
            onrcNumber: 'J40/8765/2019',
            address: 'Bd. Test 2',
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('validation.cui.invalid_checksum', $messages);
    }

    /**
     * @param ConstraintViolationListInterface<int, \Symfony\Component\Validator\ConstraintViolationInterface> $violations
     * @return list<string>
     */
    private function messageTemplates(iterable $violations): array
    {
        $out = [];
        foreach ($violations as $v) {
            $out[] = $v->getMessageTemplate();
        }

        return $out;
    }

}
