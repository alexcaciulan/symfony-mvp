<?php

declare(strict_types=1);

namespace App\Util;

/**
 * One reading of a Romanian fiscal code, whatever form a document prints it
 * in: with or without the `RO` prefix, with spaces, with leading zeros.
 *
 * Two codes name the same company when their normalized forms are equal;
 * nothing else about the string matters. Shared by the deduplication of the
 * claim positions and the party check on an uploaded invoice, so that the
 * two never disagree on whether two codes are one.
 */
final class CuiNormalizer
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        $trimmed = ltrim($digits, '0');

        return $trimmed === '' ? $digits : $trimmed;
    }

    /** True only when both codes are present and name the same company. */
    public static function same(?string $a, ?string $b): bool
    {
        $left = self::normalize($a);
        $right = self::normalize($b);

        return $left !== null && $right !== null && $left === $right;
    }
}
