<?php

declare(strict_types=1);

namespace App\Service\Case;

/**
 * Normalizes the free-text references printed on invoices and contracts so two
 * spellings of one reference compare equal.
 *
 * The same document is written "FF 0012/2025", "FF12/2025" and "Contract nr.
 * 12/2024" versus "Contract 12/2024" across a single file, because each page is
 * read on its own. Comparing the raw strings splits one invoice into two
 * positions and, worse, one contract into two causes under CPC art. 99, which
 * moves the claim to the wrong court.
 */
final class DocumentReferenceNormalizer
{
    /**
     * Null when nothing identifying is left after normalization.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = mb_strtoupper(trim($raw));
        $value = preg_replace('/\b(NR|NO|SERIA|SERIE)\.?\b/u', '', $value) ?? $value;
        // The separator between number and year is kept: it carries identity
        // ("12/2024" is not "122024"). Only decoration is dropped.
        $value = str_replace(['#', ' ', '-', '.', '_'], '', $value);
        // Leading zeros are typography, not identity: FF0012 and FF12 name one invoice.
        $value = preg_replace('/(?<!\d)0+(\d)/', '$1', $value) ?? $value;

        return $value === '' ? null : $value;
    }
}
