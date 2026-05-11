<?php

declare(strict_types=1);

namespace App\Tests\Validator\Constraints;

use App\Validator\Constraints\ValidCnp;
use App\Validator\Constraints\ValidCnpValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

final class ValidCnpValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ValidCnpValidator
    {
        return new ValidCnpValidator();
    }

    public function testNullSkipsValidation(): void
    {
        $this->validator->validate(null, new ValidCnp());

        $this->assertNoViolation();
    }

    public function testEmptyStringSkipsValidation(): void
    {
        $this->validator->validate('', new ValidCnp());

        $this->assertNoViolation();
    }

    public function testValidCnpPasses(): void
    {
        $this->validator->validate('1980715221232', new ValidCnp());

        $this->assertNoViolation();
    }

    #[DataProvider('invalidCnpsProvider')]
    public function testInvalidCnpAddsViolation(string $cnp): void
    {
        $this->validator->validate($cnp, new ValidCnp());

        $this->buildViolation('validation.cnp.invalid_checksum')->assertRaised();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCnpsProvider(): iterable
    {
        yield 'bad checksum 13 digits' => ['1234567890123'];
        yield 'starts with 0 (invalid S digit)' => ['0980715221232'];
        yield 'too short' => ['123'];
        yield 'too long' => ['19807152212320'];
    }

    public function testWrongConstraintTypeThrows(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('1980715221232', new NotBlank());
    }

    public function testNonStringValueThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate(1980715221232, new ValidCnp());
    }
}
