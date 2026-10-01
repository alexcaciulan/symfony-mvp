<?php

declare(strict_types=1);

namespace App\Service\Party;

/**
 * One canonical form of a Romanian CUI, used wherever two spellings of the same
 * company must compare equal ("RO 0123", "ro123" and "123" are one firm).
 * The stored `cui` keeps the spelling the lawyer typed, since that is what the
 * acts print; only comparisons and keys use this form.
 */
final class CuiNormalizer
{
    public static function canonical(?string $cui): ?string
    {
        if ($cui === null) {
            return null;
        }

        $compact = strtoupper(preg_replace('/[\s\x{00A0}]+/u', '', $cui) ?? '');
        if (str_starts_with($compact, 'RO')) {
            $compact = substr($compact, 2);
        }
        $compact = ltrim($compact, '0');

        return $compact === '' ? null : $compact;
    }
}
