<?php

declare(strict_types=1);

namespace App\Service\Party;

/**
 * One comparable form of a Trade Registry number, so the classic spelling on an
 * invoice (J40/11043/2003) and the single-string form the registry now issues
 * (J2003011043402: letter, year, six-digit sequence, county code, check digit)
 * compare equal when they name the same registration.
 *
 * Only comparisons use it. The stored value keeps the spelling it was read or
 * typed with, since that is what the acts print. The check digit is not
 * verified: it adds nothing to identity once letter, county, sequence and year
 * all agree.
 */
final class OnrcNumber
{
    private const CLASSIC = '/^([JFC])(\d{1,2})\/0*(\d{1,6})\/((?:19|20)\d{2})$/';
    private const COMPACT = '/^([JFC])((?:19|20)\d{2})(\d{6})(\d{2})\d$/';

    /**
     * `letter|county|sequence|year`, or null when the value is neither form.
     */
    public static function key(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $compact = strtoupper(preg_replace('/[\s\x{00A0}]+/u', '', $value) ?? '');

        if (preg_match(self::CLASSIC, $compact, $m) === 1) {
            return sprintf('%s|%d|%d|%s', $m[1], (int) $m[2], (int) $m[3], $m[4]);
        }
        if (preg_match(self::COMPACT, $compact, $m) === 1) {
            return sprintf('%s|%d|%d|%s', $m[1], (int) $m[4], (int) $m[3], $m[2]);
        }

        return null;
    }

    /**
     * Whether two spellings name the same registration. Values that parse as
     * neither form fall back to a strict, case-insensitive comparison.
     */
    public static function sameRegistration(?string $a, ?string $b): bool
    {
        $keyA = self::key($a);
        $keyB = self::key($b);
        if ($keyA !== null && $keyB !== null) {
            return $keyA === $keyB;
        }

        return mb_strtoupper(trim((string) $a)) === mb_strtoupper(trim((string) $b));
    }
}
