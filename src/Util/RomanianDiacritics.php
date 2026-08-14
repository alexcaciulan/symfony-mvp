<?php

declare(strict_types=1);

namespace App\Util;

/**
 * ANAF answers with the legacy cedilla spelling of the Romanian s/t diacritics
 * (Ş U+015E, ş U+015F, Ţ U+0162, ţ U+0163), while the SIRUTA nomenclature and
 * every string this application writes use the standard comma-below letters
 * (Ș U+0218, ș U+0219, Ț U+021A, ț U+021B).
 *
 * Left unconverted, a single generated document mixes both spellings, and a
 * string comparison against the nomenclature fails on bytes that look identical
 * on screen. Everything entering from ANAF passes through here first.
 */
final class RomanianDiacritics
{
    private const CEDILLA_TO_COMMA_BELOW = [
        "\u{015E}" => "\u{0218}", // Ş -> Ș
        "\u{015F}" => "\u{0219}", // ş -> ș
        "\u{0162}" => "\u{021A}", // Ţ -> Ț
        "\u{0163}" => "\u{021B}", // ţ -> ț
    ];

    public static function toCommaBelow(string $value): string
    {
        return strtr($value, self::CEDILLA_TO_COMMA_BELOW);
    }
}
