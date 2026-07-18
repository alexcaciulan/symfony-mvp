<?php

declare(strict_types=1);

namespace App\Service\Court;

/**
 * Reconciles the administrative vocabulary of external address sources (ANAF,
 * AI extraction, e-Factura) with the SIRUTA nomenclature stored in `county` /
 * `city`.
 *
 * The nomenclature holds bare names ("București", "Sector 1", "Holboca"), while
 * the sources decorate them: ANAF answers "MUNICIPIUL BUCUREŞTI" for the county
 * and "Sector 1 Mun. Bucureşti" for the locality, and e-Factura writes the
 * sector as "SECTOR1". Comparing those against the nomenclature never matches,
 * which surfaces to the lawyer as "Instanță nedeterminată" for every Bucharest
 * debtor.
 *
 * Deliberately separate from {@see LocalityNormalizer}, which stays a dumb
 * diacritic/case/whitespace pass shared by the court and stamp-duty queries:
 * stripping administrative words inside it would mangle legitimate names that
 * contain them (e.g. the commune "Comuna Vânători" as a City row).
 */
final class RomanianAddressNormalizer
{
    /**
     * Leading administrative qualifiers. Ordered longest-first so "municipiul"
     * is consumed before "mun". No Romanian county name starts with one of
     * these, so stripping them from a county is unambiguous.
     */
    private const COUNTY_PREFIX = '/^(?:judetul|judet|jud\.?|municipiul|mun\.?|orasul|oras|or\.?)\s+/u';

    /**
     * Leading qualifier ANAF prepends to a locality: "Mun. Cluj-Napoca",
     * "Municipiul Bacău", "Oraş Huedin". Stripped so the value matches the bare
     * `city.normalized_name`. The trailing `\s+` guards town names that merely
     * start with these letters ("Orșova", "Satu Mare" keep their leading token,
     * which has no following space). Commune addresses are handled separately by
     * {@see communeFromVillageAddress}, so "comuna" is intentionally absent here.
     */
    private const LOCALITY_PREFIX = '/^(?:municipiul|mun\.?|orasul|oras|or\.?)\s+/u';

    /** Matches "sector 1", "sectorul 1" and the e-Factura "sector1" spelling. */
    private const SECTOR = '/\bsector(?:ul)?\s*([1-6])\b/u';

    private const BUCHAREST = 'bucuresti';

    /**
     * Normalizes a county to its `county.normalized_name` form.
     * "MUNICIPIUL BUCUREŞTI" → "bucuresti"; "Jud. Cluj" → "cluj".
     */
    public static function normalizeCounty(?string $value): ?string
    {
        $normalized = LocalityNormalizer::normalize($value);
        if ($normalized === null) {
            return null;
        }

        $stripped = trim(preg_replace(self::COUNTY_PREFIX, '', $normalized) ?? $normalized);

        // A value that is nothing but a qualifier ("municipiul") carries no
        // county; keep the original rather than returning an empty match-all.
        return $stripped !== '' ? $stripped : $normalized;
    }

    /**
     * Normalizes a locality to its `city.normalized_name` form, in the context
     * of an already-normalized county.
     *
     * Three ANAF/AI spellings collapse onto the bare `city.normalized_name`:
     *   - Bucharest sectors arrive buried in noise ("Sector 1 Mun. Bucureşti" →
     *     "sector 1"). Gated on the county so a street or industrial park with
     *     the word "sector" elsewhere in the country is never rewritten.
     *   - Village addresses resolve to their commune, the UAT the nomenclature
     *     stores ("Sat Dancu Com. Holboca" → "holboca").
     *   - Municipalities and towns carry a leading qualifier ANAF prepends to
     *     every one of them ("Mun. Cluj-Napoca" → "cluj-napoca").
     */
    public static function normalizeLocality(?string $value, ?string $normalizedCounty = null): ?string
    {
        $normalized = LocalityNormalizer::normalize($value);
        if ($normalized === null) {
            return null;
        }

        if ($normalizedCounty === self::BUCHAREST && preg_match(self::SECTOR, $normalized, $m) === 1) {
            return 'sector ' . $m[1];
        }

        $commune = self::communeFromVillageAddress($normalized);
        if ($commune !== null) {
            return $commune;
        }

        $stripped = trim(preg_replace(self::LOCALITY_PREFIX, '', $normalized) ?? $normalized);

        return $stripped !== '' ? $stripped : $normalized;
    }

    /**
     * Pulls the commune out of an ANAF-style village address. Returns null when
     * the value carries no commune marker, so a plain town name is never mangled.
     *
     * Invariant: callers must pass a locality-only string. ANAF/AI/e-Factura all
     * split county and locality into separate fields; on a full composite address
     * ("Sat X Com. Y Jud. Z") the greedy capture would swallow the trailing county.
     */
    private static function communeFromVillageAddress(string $normalizedLocality): ?string
    {
        if (preg_match('/\bcom(?:\.|una)?\s+(.+)$/u', $normalizedLocality, $matches) !== 1) {
            return null;
        }

        $commune = trim($matches[1]);

        return $commune !== '' ? $commune : null;
    }
}
