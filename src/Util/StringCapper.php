<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Caps a string to a column length so an AI-extracted value longer than the
 * schema can never 500 the save on truncation. Multibyte-safe; keeps null null.
 *
 * Not for fields whose whole value drives a computation (a grouping key, a
 * dedup key): cutting those silently changes the result, so widen the column
 * instead.
 */
final class StringCapper
{
    public static function cap(?string $value, int $max): ?string
    {
        return $value !== null ? mb_substr($value, 0, $max) : null;
    }
}
