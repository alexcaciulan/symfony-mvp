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

    /** Placeholder for fully-masked CUIs (CUI is public ANAF data, but we
     * mask it in logs/AI prompts for consistency and to discourage scraping). */
    private const CUI_MASK = 'RO******';

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
     * Replaces every checksum-valid CUI (with optional `RO` prefix) in `$text`
     * with the placeholder `RO******`. Does not preserve any digits — CUI
     * carries no privacy-relevant structure to disambiguate.
     */
    public static function maskCui(string $text): string
    {
        return preg_replace_callback(
            '/(?<![A-Z0-9])(?:RO\s?)?(\d{4,10})(?!\d)/i',
            static function (array $match): string {
                if (!self::isValidCui($match[1])) {
                    return $match[0];
                }

                return self::CUI_MASK;
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
