<?php

declare(strict_types=1);

namespace App\Service\Company;

use App\Service\Address\AddressParts;
use App\Service\Court\LocalityNormalizer;
use App\Service\Court\RomanianAddressNormalizer;

/**
 * Reads an ANAF payload as a street-level address.
 *
 * ANAF splits the address over two places that do not carry the same detail:
 *
 *   - `adresa_sediu_social` is structured but shallow. For most companies its
 *     `sdetalii_Adresa` is an empty string, so the registered office alone
 *     never yields the block, staircase, floor or apartment.
 *   - `date_generale.adresa` is a flat comma-separated line that does carry
 *     them, but it mirrors `adresa_domiciliu_fiscal`, i.e. the FISCAL DOMICILE.
 *
 * Those two are the same address for most companies and genuinely differ for
 * some (CUI 14399840 has its registered office in Sector 6 and its fiscal
 * domicile in Sector 2, which is a different judecătorie). Since the address
 * printed on a somaţie and a cerere de OP is where procedural acts get served,
 * the unit tokens are imported only when the two structured blocks agree.
 */
final class AnafAddressMapper
{
    /**
     * Unit qualifiers ANAF writes in the flat line, anchored at the start of a
     * comma-separated token so a free-text fragment such as "BIROUL NR. 1" is
     * never mistaken for a labelled one.
     *
     * Alternatives are ordered longest first. The short form is a literal
     * prefix of the long one, and the trailing `(.+)` is permissive enough to
     * complete the match, so listing "bl" before "bloc" makes "BLOC 4C" parse
     * as block "OC 4C" and print that into the somaţie.
     */
    private const UNIT_LABELS = [
        'block' => '/^(?:bloc|bl)\.?\s*(.+)$/ui',
        'staircase' => '/^(?:scara|sc)\.?\s*(.+)$/ui',
        'floor' => '/^(?:etaj|et)\.?\s*(.+)$/ui',
        'apartment' => '/^(?:apartament|apt|ap)\.?\s*(.+)$/ui',
        'number' => '/^(?:numarul|numar|număr|nr)\.?\s*(.+)$/ui',
    ];

    /** A bare number continues the previous label: "ET.1", "2", "3" is one floor list. */
    private const BARE_NUMBER = '/^\d{1,3}$/';

    public function map(array $anafData): AnafAddressMapping
    {
        $fiscalDomicileDiffers = $this->fiscalDomicileDiffers($anafData);

        $details = [];
        $unit = ['block' => null, 'staircase' => null, 'floor' => null, 'apartment' => null];

        if (!$fiscalDomicileDiffers && ($anafData['flatAddress'] ?? null) !== null) {
            [$unit, $details] = $this->readFlatAddress($anafData);
        }

        // Always safe: this one belongs to the registered-office block itself.
        $officeDetails = $anafData['addressDetails'] ?? null;
        if ($officeDetails !== null && !$this->containsFingerprint($details, $officeDetails)) {
            $details[] = $officeDetails;
        }

        return new AnafAddressMapping(
            new AddressParts(
                street: $anafData['street'] ?? null,
                streetNumber: $anafData['streetNumber'] ?? null,
                block: $unit['block'],
                staircase: $unit['staircase'],
                floor: $unit['floor'],
                apartment: $unit['apartment'],
                details: $details,
                postalCode: $anafData['postalCode'] ?? null,
            ),
            $fiscalDomicileDiffers,
        );
    }

    /**
     * Compares the two structured blocks field by field. The postal code is
     * left out on purpose: ANAF often holds it on one block only, and a missing
     * code is not evidence of a different address.
     */
    private function fiscalDomicileDiffers(array $anafData): bool
    {
        $fiscal = $anafData['fiscalAddress'] ?? null;
        if (!is_array($fiscal)) {
            return false;
        }

        foreach (['street', 'streetNumber', 'city', 'county', 'addressDetails'] as $field) {
            if ($this->fingerprint($anafData[$field] ?? null) !== $this->fingerprint($fiscal[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: array{block: ?string, staircase: ?string, floor: ?string, apartment: ?string}, 1: list<string>}
     */
    private function readFlatAddress(array $anafData): array
    {
        $unit = ['block' => null, 'staircase' => null, 'floor' => null, 'apartment' => null];
        $details = [];

        $normalizedCounty = RomanianAddressNormalizer::normalizeCounty($anafData['county'] ?? null);
        $normalizedLocality = RomanianAddressNormalizer::normalizeLocality($anafData['city'] ?? null, $normalizedCounty);
        $streetFingerprint = $this->fingerprint($anafData['street'] ?? null);

        foreach ($this->tokenize($anafData['flatAddress']) as $token) {
            $labelled = false;

            foreach (self::UNIT_LABELS as $part => $pattern) {
                if (preg_match($pattern, $token, $matches) !== 1) {
                    continue;
                }

                $labelled = true;
                // The street number already came from the structured block,
                // which is the authoritative one; the flat line only confirms it.
                if ($part !== 'number') {
                    $unit[$part] = trim($matches[1]);
                }
                break;
            }

            if ($labelled) {
                continue;
            }

            // Everything else is free text, minus the county, the locality and
            // the street, which the flat line repeats and which live in their
            // own fields. Leaving them in is what printed "Bucureşti" three
            // times on the same line.
            if (RomanianAddressNormalizer::normalizeCounty($token) === $normalizedCounty
                || RomanianAddressNormalizer::normalizeLocality($token, $normalizedCounty) === $normalizedLocality
                || $this->fingerprint($token) === $streetFingerprint
                || $this->containsFingerprint($details, $token)
            ) {
                continue;
            }

            $details[] = $token;
        }

        return [$unit, $details];
    }

    /**
     * Splits the flat line on commas, then re-attaches bare numbers to the
     * token before them: ANAF writes a multi-floor tenancy as "ET.1,2,3,5,8",
     * and splitting naively would print ", 2, 3, 5, 8" into the somaţie.
     *
     * @return list<string>
     */
    private function tokenize(string $flatAddress): array
    {
        $tokens = [];

        foreach (explode(',', $flatAddress) as $raw) {
            $token = trim($raw);
            if ($token === '') {
                continue;
            }

            if ($tokens !== [] && preg_match(self::BARE_NUMBER, $token) === 1) {
                $tokens[array_key_last($tokens)] .= ',' . $token;
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /** @param list<string> $details */
    private function containsFingerprint(array $details, string $candidate): bool
    {
        $fingerprint = $this->fingerprint($candidate);

        foreach ($details as $detail) {
            if ($this->fingerprint($detail) === $fingerprint) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case, diacritics, spacing and punctuation all vary between the flat line
     * and the structured block for the same value ("STR TURTURELELOR" against
     * "Str. Turturelelor"), so comparisons run on alphanumerics only.
     */
    private function fingerprint(?string $value): ?string
    {
        $normalized = LocalityNormalizer::normalize($value);
        if ($normalized === null) {
            return null;
        }

        $stripped = preg_replace('/[^a-z0-9]/u', '', $normalized) ?? $normalized;

        return $stripped === '' ? null : $stripped;
    }
}
