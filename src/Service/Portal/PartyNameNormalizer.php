<?php

declare(strict_types=1);

namespace App\Service\Portal;

/**
 * Normalizes party names for fuzzy matching between our records and portal.just.ro.
 *
 * The portal returns only party NAMES (no CUI/CNP), so case discovery matches on
 * names. Names differ in casing, diacritics, legal-form tokens ("SC ACME SRL" vs
 * "ACME") and punctuation, so we strip those before comparing.
 */
final class PartyNameNormalizer
{
    /** Legal-form tokens dropped before comparison (uppercase, no punctuation). */
    private const LEGAL_FORM_TOKENS = [
        'SC', 'SRL', 'SRLD', 'SA', 'SCA', 'SNC', 'SCS', 'PFA', 'II', 'IF', 'PF', 'PJ',
    ];

    public static function normalize(string $name): string
    {
        $name = self::stripDiacritics(mb_strtolower(trim($name)));

        // Join dotted abbreviations ("S.C." → "SC", "S.R.L." → "SRL") before
        // tokenizing, so legal-form tokens are recognised and dropped.
        $name = str_replace('.', '', $name);

        // Replace any remaining non-alphanumeric run with a single space.
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';
        $name = mb_strtoupper($name);

        $tokens = array_filter(
            explode(' ', $name),
            static fn (string $t): bool => $t !== '' && !in_array($t, self::LEGAL_FORM_TOKENS, true),
        );

        return implode(' ', $tokens);
    }

    /**
     * True when the two names refer to the same party. Compares normalized forms
     * and accepts a containment match in either direction ("ACME" ⊂ "ACME GRUP").
     *
     * Substring matching is intentionally permissive (the portal exposes no
     * CUI/CNP, only names), so short names can yield false positives ("ION" ⊂
     * "IONESCU"). The lawyer's explicit confirmation before activation is the
     * safeguard: matches are only ever surfaced as suggestions, never auto-applied.
     */
    public static function matches(string $a, string $b): bool
    {
        $na = self::normalize($a);
        $nb = self::normalize($b);

        if ($na === '' || $nb === '') {
            return false;
        }

        return $na === $nb || str_contains($na, $nb) || str_contains($nb, $na);
    }

    private static function stripDiacritics(string $text): string
    {
        return strtr($text, [
            'ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't',
        ]);
    }
}
