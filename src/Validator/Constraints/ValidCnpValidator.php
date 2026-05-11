<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Util\PiiMasker;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidCnpValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCnp) {
            throw new UnexpectedTypeException($constraint, ValidCnp::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (!PiiMasker::isValidCnp($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
