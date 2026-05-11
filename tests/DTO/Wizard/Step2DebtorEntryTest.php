<?php

declare(strict_types=1);

namespace App\Tests\DTO\Wizard;

use App\DTO\Wizard\Step2DebtorEntry;
use App\Enum\PersonType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class Step2DebtorEntryTest extends KernelTestCase
{
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
            address: 'Bd. Test 2, Cluj-Napoca',
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
            address: 'Bd. Test 2',
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('wizard.step2.error.name_required', $messages);
    }

    public function testMissingAddressIsInvalid(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('wizard.step2.error.address_required', $messages);
    }

    public function testInvalidCuiIsRejected(): void
    {
        $dto = new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
            cui: '99999999',
            address: 'Bd. Test 2',
        );

        $violations = $this->validator->validate($dto);

        $messages = $this->messageTemplates($violations);
        self::assertContains('validation.cui.invalid_checksum', $messages);
    }

    /**
     * @param \Symfony\Component\Validator\ConstraintViolationListInterface<int, \Symfony\Component\Validator\ConstraintViolationInterface> $violations
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
