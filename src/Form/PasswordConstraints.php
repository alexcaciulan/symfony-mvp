<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

/**
 * Single source of truth for the new-password policy, applied to every entry point
 * (registration, profile change, reset): a length floor plus a breach check. NIST 800-63B
 * favours length + breach screening over composition/entropy rules, so no PasswordStrength.
 */
final class PasswordConstraints
{
    public const MIN_LENGTH = 12;

    /**
     * @return Constraint[]
     */
    public static function forNewPassword(): array
    {
        return [
            new NotBlank(message: 'form.password.required'),
            new Length(
                min: self::MIN_LENGTH,
                max: 4096,
                minMessage: 'form.password.min_length',
            ),
            // Screens against known breaches via HaveIBeenPwned k-anonymity. skipOnError so
            // a network hiccup never blocks a legitimate password change (disabled in test).
            new NotCompromisedPassword(
                message: 'form.password.compromised',
                skipOnError: true,
            ),
        ];
    }
}
