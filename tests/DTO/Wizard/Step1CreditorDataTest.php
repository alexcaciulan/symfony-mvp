<?php

declare(strict_types=1);

namespace App\Tests\DTO\Wizard;

use App\DTO\Wizard\Step1CreditorData;
use App\Enum\PersonType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class Step1CreditorDataTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testCreditorIdSetMakesManualFieldsOptional(): void
    {
        $dto = new Step1CreditorData(creditorId: 42);

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations);
    }

    public function testFullManualFillIsValid(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'SC Foo SRL',
            cui: '15193236',
            address: 'Str. Test 1, București',
            email: 'foo@example.com',
            iban: 'RO49AAAA1B31007593840000',
        );

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testNoCreditorIdAndMissingNameTriggersExpression(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PJ,
            address: 'Str. Test 1',
        );

        $violations = $this->validator->validate($dto);

        self::assertGreaterThan(0, $violations->count());
        $messages = [];
        foreach ($violations as $v) {
            $messages[] = $v->getMessageTemplate();
        }
        self::assertContains('wizard.step1.error.either_id_or_manual', $messages);
    }

    public function testNoCreditorIdAndMissingAddressTriggersExpression(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'SC Foo SRL',
        );

        $violations = $this->validator->validate($dto);

        $messages = [];
        foreach ($violations as $v) {
            $messages[] = $v->getMessageTemplate();
        }
        self::assertContains('wizard.step1.error.either_id_or_manual', $messages);
    }

    public function testInvalidCuiIsRejected(): void
    {
        $dto = new Step1CreditorData(
            creditorId: 1,
            cui: '12345678',
        );

        $violations = $this->validator->validate($dto);

        $messages = [];
        foreach ($violations as $v) {
            $messages[] = $v->getMessageTemplate();
        }
        self::assertContains('validation.cui.invalid_checksum', $messages);
    }

    public function testInvalidEmailIsRejected(): void
    {
        $dto = new Step1CreditorData(
            creditorId: 1,
            email: 'not-an-email',
        );

        $violations = $this->validator->validate($dto);

        self::assertSame(1, $violations->count());
        self::assertSame('validation.email.invalid', $violations[0]->getMessageTemplate());
    }

    public function testInvalidIbanIsRejected(): void
    {
        $dto = new Step1CreditorData(
            creditorId: 1,
            iban: 'RO12XXX',
        );

        $violations = $this->validator->validate($dto);

        self::assertSame(1, $violations->count());
        self::assertSame('validation.iban.invalid_format', $violations[0]->getMessageTemplate());
    }

    public function testInvalidCnpIsRejected(): void
    {
        $dto = new Step1CreditorData(
            creditorId: 1,
            personalId: '1234567890123',
        );

        $violations = $this->validator->validate($dto);

        $messages = [];
        foreach ($violations as $v) {
            $messages[] = $v->getMessageTemplate();
        }
        self::assertContains('validation.cnp.invalid_checksum', $messages);
    }
}
