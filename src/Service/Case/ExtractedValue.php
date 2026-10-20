<?php

declare(strict_types=1);

namespace App\Service\Case;

/**
 * Lenient readers for scalar values of an extraction payload, which is model
 * output and may carry any type in any field.
 */
final class ExtractedValue
{
    public static function string(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function date(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))->setTime(0, 0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    /** ISO code as positions carry it: the model may answer "ron" or "lei". */
    public static function currency(mixed $value): ?string
    {
        $code = self::string($value);
        if ($code === null) {
            return null;
        }
        $code = strtoupper(trim($code));

        return $code === 'LEI' ? 'RON' : $code;
    }
}
