<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What the inspection of an uploaded signed file found. Reports a detected
 * signature only, never a validated qualified signature.
 */
enum SignatureStatus: string
{
    case MISSING = 'MISSING';
    case PRESENT_MATCHED = 'PRESENT_MATCHED';
    /** Signed, but matched to the generated file only by its text: the lawyer confirms. */
    case PRESENT_PROBABLE = 'PRESENT_PROBABLE';
    case MISMATCH = 'MISMATCH';
    case NOT_SIGNED = 'NOT_SIGNED';
    case DAMAGED = 'DAMAGED';

    public function label(): string
    {
        return 'enum.signature_status.' . $this->value;
    }
}
