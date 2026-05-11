<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Util\PiiMasker;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidCuiValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCui) {
            throw new UnexpectedTypeException($constraint, ValidCui::class);
        }

        if ($value === null || $value === '') {
            return;
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $digits = str_starts_with($value, 'RO') ? substr($value, 2) : $value;

        if (!PiiMasker::isValidCui($digits)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
