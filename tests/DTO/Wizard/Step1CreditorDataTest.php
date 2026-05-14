<?php

declare(strict_types=1);

namespace App\Tests\DTO\Wizard;

use App\DTO\Wizard\Step1CreditorData;
use App\Enum\PersonType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validation contract:
 *  - `Default` group covers `cui`/`personalId`/`email`/`iban` regex+checksum
 *    constraints regardless of how the form was filled.
 *  - `manual` group covers the manual creditor path (`personType`, `name`,
 *    `address` NotBlank + the `validateConditionalRequiredFields` callback
 *    that enforces CUI+ONRC for PJ or CNP for PF). The form drops this group
 *    when `creditorId` is set (autocomplete path), so we exercise both groups
 *    explicitly in tests.
 */
final class Step1CreditorDataTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testCreditorIdSetMakesManualFieldsOptionalUnderDefaultGroup(): void
    {
        $dto = new Step1CreditorData(creditorId: 42);

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations);
    }

    public function testCreditorIdSetSkipsManualGroup(): void
    {
        // Autocomplete path: even when we pass ['Default','manual'], the
        // form's validation_groups callback drops 'manual' once creditorId is
        // set. To mirror that here, we just don't include 'manual'.
        $dto = new Step1CreditorData(creditorId: 42);

        $violations = $this->validator->validate($dto, null, ['Default']);

        self::assertCount(0, $violations);
    }

    public function testFullManualPjFillIsValid(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'SC Foo SRL',
            cui: '15193236',
            onrcNumber: 'J40/1234/2018',
            address: 'Str. Test 1, București',
            email: 'foo@example.com',
            iban: 'RO49RNCB0082004480010001',
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'manual']);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testFullManualPfFillIsValid(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PF,
            name: 'Ion Popescu',
            personalId: '1980715221232',
            address: 'Str. Test 1, București',
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'manual']);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testManualPathMissingNameProducesViolationOnNameProperty(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PJ,
            cui: '15193236',
            onrcNumber: 'J40/1234/2018',
            address: 'Str. Test 1',
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'manual']);

        self::assertViolation($violations, 'name', 'wizard.step1.error.name_required');
    }

    public function testManualPathMissingAddressProducesViolationOnAddressProperty(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'SC Foo SRL',
            cui: '15193236',
            onrcNumber: 'J40/1234/2018',
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'manual']);

        self::assertViolation($violations, 'address', 'wizard.step1.error.address_required');
    }

    public function testManualPathMissingPersonTypeProducesViolationOnPersonTypeProperty(): void
    {
        $dto = new Step1CreditorData(
            name: 'SC Foo SRL',
            address: 'Str. Test 1',
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'manual']);

        self::assertViolation($violations, 'personType', 'wizard.step1.error.person_type_required');
    }

    public function testPjPathMissingCuiProducesViolationOnCuiProperty(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'SC Foo SRL',
            onrcNumber: 'J40/1234/2018',
            address: 'Str. Test 1',
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'manual']);

        self::assertViolation($violations, 'cui', 'wizard.step1.error.cui_required');
    }

    public function testPjPathMissingOnrcProducesViolationOnOnrcProperty(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'SC Foo SRL',
            cui: '15193236',
            address: 'Str. Test 1',
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'manual']);

        self::assertViolation($violations, 'onrcNumber', 'wizard.step1.error.onrc_required');
    }

    public function testPfPathMissingCnpProducesViolationOnPersonalIdProperty(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PF,
            name: 'Ion Popescu',
            address: 'Str. Test 1',
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'manual']);

        self::assertViolation($violations, 'personalId', 'wizard.step1.error.cnp_required');
    }

    public function testPjPathDoesNotRequireCnp(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PJ,
            name: 'SC Foo SRL',
            cui: '15193236',
            onrcNumber: 'J40/1234/2018',
            address: 'Str. Test 1',
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'manual']);

        self::assertNoViolation($violations, 'personalId');
    }

    public function testPfPathDoesNotRequireCuiOrOnrc(): void
    {
        $dto = new Step1CreditorData(
            personType: PersonType::PF,
            name: 'Ion Popescu',
            personalId: '1980715221232',
            address: 'Str. Test 1',
        );

        $violations = $this->validator->validate($dto, null, ['Default', 'manual']);

        self::assertNoViolation($violations, 'cui');
        self::assertNoViolation($violations, 'onrcNumber');
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

    private static function assertViolation(
        ConstraintViolationListInterface $violations,
        string $expectedPath,
        string $expectedTemplate,
    ): void {
        foreach ($violations as $v) {
            if ($v->getPropertyPath() === $expectedPath && $v->getMessageTemplate() === $expectedTemplate) {
                self::assertTrue(true);

                return;
            }
        }

        $debug = [];
        foreach ($violations as $v) {
            $debug[] = sprintf('%s: %s', $v->getPropertyPath(), $v->getMessageTemplate());
        }

        self::fail(sprintf(
            'Expected violation "%s" on property "%s" but got: %s',
            $expectedTemplate,
            $expectedPath,
            $debug ? implode(' | ', $debug) : '(no violations)',
        ));
    }

    private static function assertNoViolation(
        ConstraintViolationListInterface $violations,
        string $forbiddenPath,
    ): void {
        foreach ($violations as $v) {
            if ($v->getPropertyPath() === $forbiddenPath) {
                self::fail(sprintf(
                    'Unexpected violation on property "%s": %s',
                    $forbiddenPath,
                    $v->getMessageTemplate(),
                ));
            }
        }

        self::assertTrue(true);
    }
}
