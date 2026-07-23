<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Parses a money amount the way a Romanian keyboard and a Romanian invoice write
 * it: comma as the decimal mark, dot as the thousands separator. Anything that
 * is not a plain positive number is rejected, so a mistyped or pasted value
 * leaves the previously known figure standing instead of corrupting the claim.
 */
final class RomanianAmountParser
{
    public static function parse(string $raw): ?float
    {
        $s = str_replace(' ', '', trim($raw));
        // Digits, comma and dot only: is_numeric() would otherwise accept "1e6"
        // and turn a mistyped sum into a million.
        if ($s === '' || preg_match('/[^0-9.,]/', $s) === 1) {
            return null;
        }

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Both separators present: the decimal mark is whichever is rightmost.
            // Romanian writes the comma last ("1.234,56"). The American form
            // ("1,234.56") is rejected, not guessed, because guessing silently
            // turns 1,234.56 into 1.23 and files the wrong sum with the court.
            if ($lastComma < $lastDot) {
                return null;
            }
            $s = str_replace(['.', ','], ['', '.'], $s);
        } elseif ($lastComma !== false) {
            // A lone comma is the decimal mark; a second one is nonsense.
            if (substr_count($s, ',') > 1) {
                return null;
            }
            $s = str_replace(',', '.', $s);
        } elseif ($lastDot !== false) {
            $decimals = strlen($s) - $lastDot - 1;
            // Dots grouping thousands ("1.234", "1.234.567") read as an integer;
            // a single dot with one or two trailing digits is the decimal mark
            // ("493.23"). Anything else (four-plus digits before a three-digit
            // group, more than two decimals) is neither, so reject it.
            if (substr_count($s, '.') > 1 || $decimals === 3) {
                if (preg_match('/^\d{1,3}(\.\d{3})+$/', $s) !== 1) {
                    return null;
                }
                $s = str_replace('.', '', $s);
            } elseif ($decimals > 3) {
                return null;
            }
        }

        if (!is_numeric($s)) {
            return null;
        }
        $value = (float) $s;

        return $value > 0.0 ? round($value, 2) : null;
    }
}
