<?php

declare(strict_types=1);

namespace App\Tests\DTO\Wizard;

use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\Enum\PersonType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class Step2DebtorsDataTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testOneValidDebtorPasses(): void
    {
        $dto = new Step2DebtorsData([$this->makeValidDebtor()]);

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testZeroDebtorsIsInvalid(): void
    {
        $dto = new Step2DebtorsData([]);

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('wizard.step2.error.at_least_one_debtor', $violations[0]->getMessageTemplate());
    }

    public function testSixDebtorsIsInvalid(): void
    {
        $dto = new Step2DebtorsData(array_fill(0, 6, $this->makeValidDebtor()));

        $violations = $this->validator->validate($dto);

        $messages = [];
        foreach ($violations as $v) {
            $messages[] = $v->getMessageTemplate();
        }
        self::assertContains('wizard.step2.error.too_many_debtors', $messages);
    }

    public function testRecursiveValidationCatchesInvalidEntry(): void
    {
        $invalid = new Step2DebtorEntry(
            personType: PersonType::PJ,
            // missing name + address + cui + onrcNumber — every required PJ
            // field should produce its own violation on its own property path.
        );
        $dto = new Step2DebtorsData([$invalid]);

        $violations = $this->validator->validate($dto);

        $messages = [];
        foreach ($violations as $v) {
            $messages[] = $v->getMessageTemplate();
        }
        self::assertContains('wizard.step2.error.name_required', $messages);
        self::assertContains('wizard.step2.error.address_required', $messages);
        self::assertContains('wizard.step2.error.cui_required', $messages);
        self::assertContains('wizard.step2.error.onrc_required', $messages);
    }

    private function makeValidDebtor(): Step2DebtorEntry
    {
        return new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'SC Bar SRL',
            cui: '14186770',
            onrcNumber: 'J40/8765/2019',
            address: 'Bd. Test 2, Cluj-Napoca',
            // Mandatory BPI attestation — a debtor without it never validates.
            insolvencyCheckedAt: new \DateTimeImmutable(),
        );
    }
}
