<?php

declare(strict_types=1);

namespace App\Tests\Validator\Constraints;

use App\Validator\Constraints\ValidCui;
use App\Validator\Constraints\ValidCuiValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ValidCuiValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ValidCuiValidator
    {
        return new ValidCuiValidator();
    }

    public function testNullSkipsValidation(): void
    {
        $this->validator->validate(null, new ValidCui());

        $this->assertNoViolation();
    }

    public function testEmptyStringSkipsValidation(): void
    {
        $this->validator->validate('', new ValidCui());

        $this->assertNoViolation();
    }

    #[DataProvider('validCuisProvider')]
    public function testValidCuiPasses(string $cui): void
    {
        $this->validator->validate($cui, new ValidCui());

        $this->assertNoViolation();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validCuisProvider(): iterable
    {
        yield 'plain digits 8 chars' => ['15193236'];
        yield 'plain digits 8 chars alt' => ['14186770'];
        yield 'with RO prefix' => ['RO15193236'];
    }

    #[DataProvider('invalidCuisProvider')]
    public function testInvalidCuiAddsViolation(string $cui): void
    {
        $this->validator->validate($cui, new ValidCui());

        $this->buildViolation('validation.cui.invalid_checksum')->assertRaised();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCuisProvider(): iterable
    {
        yield 'bad checksum' => ['12345678'];
        yield 'too short' => ['123'];
        yield 'all zeros' => ['000000000'];
        yield 'too long' => ['12345678901'];
    }

    public function testWrongConstraintTypeThrows(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('15193236', new NotBlank());
    }

    public function testNonStringValueThrows(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate(15193236, new ValidCui());
    }
}
