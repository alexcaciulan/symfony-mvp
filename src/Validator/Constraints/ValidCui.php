<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Validates that a string is a structurally valid Romanian CUI (cod unic
 * de înregistrare / VAT-style fiscal code) per the ANAF checksum algorithm.
 *
 * Accepts an optional leading "RO" (case-sensitive — ANAF uses uppercase)
 * which is stripped before checksum evaluation. Empty / null values are
 * skipped (combine with `NotBlank` where the field is required).
 *
 * Reuses `App\Util\PiiMasker::isValidCui()` so the validator and the PII
 * scrubber share a single algorithm — they can't drift.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ValidCui extends Constraint
{
    public string $message = 'validation.cui.invalid_checksum';
}
