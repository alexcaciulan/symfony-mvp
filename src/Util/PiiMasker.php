<?php

namespace App\Util;

/**
 * Utility for validating and masking Romanian personally-identifying numeric
 * identifiers — CNP (personal numeric code, OUG 97/2005) and CUI (fiscal
 * registration code, ANAF). Single source of truth for the checksum
 * algorithms across the codebase.
 *
 * Used by:
 *   - {@see App\Service\Extraction\PdfParserExtractionStrategy} — to validate
 *     candidates before assigning them to creditor/debtor sections.
 *   - {@see App\Service\Extraction\OcrTextExtractionStrategy} (Pas 2.5.7) — to
 *     mask CNPs in OCR text BEFORE sending the prompt to the AI provider, then
 *     restore them in the structured response (round-trip via buildCnpMap +
 *     restoreCnp). GDPR data-minimisation under art. 25.
 *   - Audit logs and any other layer that displays document data outside the
 *     server boundary should call maskCnp/maskCui first.
 *
 * Pattern: `final class` with all-static methods, no DI registration. Same
 * pattern as {@see App\Service\Court\LocalityNormalizer}.
 */
final class PiiMasker
{
    /** ANAF CUI checksum weights, applied to a 9-digit left-padded body. */
    private const CUI_WEIGHTS = [7, 5, 3, 2, 1, 7, 5, 3, 2];

    /**
     * Official CNP weights per OUG 97/2005 — applied to the first 12 digits;
     * sum mod 11 yields the check digit (or 1 if mod 11 == 10).
     */
    private const CNP_WEIGHTS = [2, 7, 9, 1, 4, 6, 3, 5, 8, 2, 7, 9];

    /** Placeholder for masked CNPs — preserves the last 4 digits as `XXXX`. */
    private const CNP_MASK_PREFIX = '***-***-';

    /**
     * Placeholders for masked CUIs. Two variants because the `RO` prefix
     * carries semantic information in Romanian fiscal law:
     *   - With `RO` (e.g. `RO15193236`): the entity is a VAT payer (registered
     *     in scopuri TVA per Codul Fiscal art. 316-317).
     *   - Without `RO` (e.g. `15193236`, often called CIF instead of CUI): the
     *     entity has a fiscal code but is NOT registered for VAT.
     * Masking that flattened both to `RO******` would falsely imply that all
     * entities are VAT payers in audit logs and AI prompts. We preserve the
     * prefix-presence distinction by using two different placeholders.
     */
    private const CUI_MASK_VAT_PAYER = 'RO******';

    private const CUI_MASK_NON_VAT = '******';

    /**
     * Placeholder prefix for IBAN round-trip via {@see self::buildIbanMap()}.
     * Tokens are formatted `IBAN_PLACEHOLDER_001`, `IBAN_PLACEHOLDER_002`, etc.
     * Numeric suffix is fixed-width (3 digits, zero-padded) so the
     * descending-length sort in {@see self::restoreIban()} is preventive
     * against any future longer-suffix variant rather than load-bearing today.
     */
    public const IBAN_PLACEHOLDER_PREFIX = 'IBAN_PLACEHOLDER_';

    /** Generic mask for IBANs in audit logs / display where round-trip isn't needed. */
    private const IBAN_MASK_DISPLAY = 'RO**REDACTED**';

    /**
     * Romanian IBAN structure (ISO 13616 + ECBS RO branch):
     *   `RO` + 2 check digits + 4 alphabetic bank code + 16 alphanumeric BBAN.
     * Total length 24. Used both by maskIban and buildIbanMap.
     */
    private const IBAN_PATTERN = '/RO\d{2}[A-Z]{4}[A-Z0-9]{16}/';

    // ---------- validators ----------

    /**
     * Validates a CNP (13-digit Romanian personal numeric code) against the
     * OUG 97/2005 checksum algorithm. Also rejects S=0 (the "century/gender"
     * digit must be 1..9 — a CNP starting with 0 is structurally invalid).
     */
    public static function isValidCnp(string $digits): bool
    {
        if (mb_strlen($digits) !== 13) {
            return false;
        }

        $firstDigit = (int) $digits[0];
        if ($firstDigit < 1 || $firstDigit > 9) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ((int) $digits[$i]) * self::CNP_WEIGHTS[$i];
        }

        $computed = $sum % 11;
        if ($computed === 10) {
            $computed = 1;
        }

        return $computed === (int) $digits[12];
    }

    /**
     * Validates a CUI (Romanian fiscal registration code) against the ANAF
     * checksum algorithm. Real ANAF-issued CUIs have at least 4 digits in
     * practice; pure-zero bodies are rejected even if they trivially satisfy
     * the checksum (sum = 0 = check digit).
     */
    public static function isValidCui(string $digits): bool
    {
        $length = mb_strlen($digits);
        if ($length < 4 || $length > 10) {
            return false;
        }

        $checkDigit = (int) $digits[$length - 1];
        $body = substr($digits, 0, $length - 1);

        if ((int) $body === 0) {
            return false;
        }

        $padded = str_pad($body, 9, '0', STR_PAD_LEFT);

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $padded[$i]) * self::CUI_WEIGHTS[$i];
        }

        $computed = ($sum * 10) % 11;
        if ($computed === 10) {
            $computed = 0;
        }

        return $computed === $checkDigit;
    }

    // ---------- masking ----------

    /**
     * Replaces every checksum-valid CNP in `$text` with the placeholder
     * `***-***-XXXX`, where XXXX are the last 4 digits of the original CNP.
     * Last 4 are preserved so audit logs / multi-debtor documents remain
     * disambiguatable, while the first 9 (containing date of birth + county
     * code + serial) are stripped.
     *
     * Strings of 13 digits that fail the checksum are left intact — they
     * are not CNPs.
     */
    public static function maskCnp(string $text): string
    {
        return preg_replace_callback(
            '/(?<!\d)(\d{13})(?!\d)/',
            static function (array $match): string {
                if (!self::isValidCnp($match[1])) {
                    return $match[0];
                }

                return self::CNP_MASK_PREFIX . substr($match[1], -4);
            },
            $text,
        ) ?? $text;
    }

    /**
     * Replaces every checksum-valid CUI in `$text` with a placeholder that
     * preserves whether the original carried the `RO` prefix:
     *   - `RO15193236` → `RO******` (VAT payer)
     *   - `15193236`   → `******`   (CIF, non-VAT-payer)
     * The prefix distinction is meaningful in Romanian fiscal law (TVA vs CIF
     * per Codul Fiscal art. 316) and must survive masking so audit logs and
     * AI-extraction prompts don't falsify VAT status.
     */
    public static function maskCui(string $text): string
    {
        return preg_replace_callback(
            '/(?<![A-Z0-9])(RO\s?)?(\d{4,10})(?!\d)/i',
            static function (array $match): string {
                if (!self::isValidCui($match[2])) {
                    return $match[0];
                }

                return $match[1] !== '' ? self::CUI_MASK_VAT_PAYER : self::CUI_MASK_NON_VAT;
            },
            $text,
        ) ?? $text;
    }

    // ---------- round-trip support for AI prompts ----------

    /**
     * Scans `$text` for valid CNPs and returns a map original → unique
     * placeholder. Use this BEFORE sending the text to an AI provider, then
     * use {@see self::restoreCnp()} to re-substitute the originals into the
     * AI's structured response.
     *
     * Uniqueness: when two distinct CNPs share the same last 4 digits (rare
     * but possible), placeholders are suffixed with `#1`, `#2`, etc. so the
     * round-trip remains lossless.
     *
     * @return array<string, string> [original_cnp => placeholder]
     */
    public static function buildCnpMap(string $text): array
    {
        $matches = [];
        if (!preg_match_all('/(?<!\d)(\d{13})(?!\d)/', $text, $matches)) {
            return [];
        }

        $map = [];
        $usedPlaceholders = [];
        foreach (array_unique($matches[1]) as $candidate) {
            if (!self::isValidCnp($candidate)) {
                continue;
            }

            $base = self::CNP_MASK_PREFIX . substr($candidate, -4);
            $placeholder = $base;
            $suffix = 1;
            while (isset($usedPlaceholders[$placeholder])) {
                $placeholder = $base . '#' . $suffix++;
            }
            $usedPlaceholders[$placeholder] = true;
            $map[$candidate] = $placeholder;
        }

        return $map;
    }

    /**
     * Reverse of {@see self::buildCnpMap()}: replaces placeholders in
     * `$maskedText` with the original CNPs from `$map`. Safe even when the
     * AI response normalises whitespace or rewraps lines — substitution is
     * a literal `str_replace`, not regex-anchored.
     *
     * @param array<string, string> $map [original => placeholder] from buildCnpMap()
     */
    public static function restoreCnp(string $maskedText, array $map): string
    {
        if ($map === []) {
            return $maskedText;
        }

        // CRITICAL: when two distinct CNPs share the same last 4 digits, the map
        // contains both `***-***-XXXX` and `***-***-XXXX#1`. PHP's str_replace()
        // processes its first array sequentially — substituting the shorter
        // placeholder first would corrupt the inside of the longer one. Sort by
        // placeholder length DESCENDING to substitute longest-first, preserving
        // the round-trip property even under last-4 collisions.
        $placeholders = array_values($map);
        $originals = array_keys($map);

        $indexed = [];
        foreach ($placeholders as $i => $placeholder) {
            $indexed[] = ['placeholder' => $placeholder, 'original' => $originals[$i]];
        }
        usort($indexed, static fn(array $a, array $b) => strlen($b['placeholder']) <=> strlen($a['placeholder']));

        $sortedPlaceholders = array_column($indexed, 'placeholder');
        $sortedOriginals = array_column($indexed, 'original');

        return str_replace($sortedPlaceholders, $sortedOriginals, $maskedText);
    }

    /**
     * Replaces every Romanian IBAN in `$text` with a generic redacted token.
     * Use this for audit logs / display surfaces where the IBAN is not needed
     * downstream. For AI-prompt round-trip use {@see self::buildIbanMap()} +
     * {@see self::restoreIban()} instead — masking via this method is one-way.
     */
    public static function maskIban(string $text): string
    {
        return preg_replace(self::IBAN_PATTERN, self::IBAN_MASK_DISPLAY, $text) ?? $text;
    }

    /**
     * Scans `$text` for Romanian IBANs and returns a map original → unique
     * placeholder. Companion to {@see self::buildCnpMap()} for the
     * Pas 2.5.7 OcrTextExtractionStrategy round-trip: mask BEFORE the AI call,
     * use {@see self::restoreIban()} on the structured response.
     *
     * Note: IBAN structural validity (pattern + length) is checked here, but
     * mod-97 checksum validation is NOT — fragments produced by bad OCR may
     * fail checksum, and the round-trip remains correct regardless because
     * substitution is symmetric. Strategies that need validated IBANs run
     * checksum validation post-restore.
     *
     * @return array<string, string> [original_iban => placeholder]
     */
    public static function buildIbanMap(string $text): array
    {
        $matches = [];
        if (!preg_match_all(self::IBAN_PATTERN, $text, $matches)) {
            return [];
        }

        $map = [];
        $counter = 1;
        foreach (array_unique($matches[0]) as $iban) {
            $map[$iban] = self::IBAN_PLACEHOLDER_PREFIX . str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
            $counter++;
        }

        return $map;
    }

    /**
     * Reverse of {@see self::buildIbanMap()}: replaces placeholders in
     * `$maskedText` with the original IBANs from `$map`. Mirrors
     * {@see self::restoreCnp()} — substitutes longest placeholder first to
     * remain robust against any future numeric-overflow suffix variants
     * (today all placeholders share the same fixed length, so the sort is
     * a no-op but cheap insurance).
     *
     * @param array<string, string> $map [original => placeholder] from buildIbanMap()
     */
    public static function restoreIban(string $maskedText, array $map): string
    {
        if ($map === []) {
            return $maskedText;
        }

        $placeholders = array_values($map);
        $originals = array_keys($map);

        $indexed = [];
        foreach ($placeholders as $i => $placeholder) {
            $indexed[] = ['placeholder' => $placeholder, 'original' => $originals[$i]];
        }
        usort($indexed, static fn(array $a, array $b) => strlen($b['placeholder']) <=> strlen($a['placeholder']));

        $sortedPlaceholders = array_column($indexed, 'placeholder');
        $sortedOriginals = array_column($indexed, 'original');

        return str_replace($sortedPlaceholders, $sortedOriginals, $maskedText);
    }

    /**
     * Recursively masks every CNP found in a structured payload (typical use:
     * `oldData` / `newData` arrays passed to {@see AuditLogService::log()}).
     * String leaves are processed via {@see self::maskCnp()}; nested arrays
     * are walked depth-first; non-string scalars are left intact.
     *
     * Defense-in-depth helper for callers persisting structured audit data
     * — mirrors the per-string masking but is safe to apply to whole payloads
     * without explicit tree walks at each call site.
     *
     * @param array<mixed, mixed> $payload
     * @return array<mixed, mixed>
     */
    public static function maskCnpInArray(array $payload): array
    {
        array_walk_recursive(
            $payload,
            static function (mixed &$value): void {
                if (is_string($value)) {
                    $value = self::maskCnp($value);
                }
            },
        );

        return $payload;
    }
}
