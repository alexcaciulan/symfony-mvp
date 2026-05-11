<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Validates that a string is a structurally valid Romanian CNP (cod numeric
 * personal — 13-digit personal numeric code) per the OUG 97/2005 checksum
 * algorithm.
 *
 * Empty / null values are skipped (combine with `NotBlank` where the field
 * is required). Delegates the checksum to `App\Util\PiiMasker::isValidCnp()`
 * so validator and PII scrubber share a single algorithm.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ValidCnp extends Constraint
{
    public string $message = 'validation.cnp.invalid_checksum';
}
