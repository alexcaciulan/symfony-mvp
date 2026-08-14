<?php

declare(strict_types=1);

namespace App\Service\Court;

use App\Repository\CityRepository;
use App\Repository\CountyRepository;

/**
 * Rewrites a county and a locality coming from an external source into the
 * exact spelling the SIRUTA nomenclature stores ("MUNICIPIUL BUCUREŞTI" and
 * "Sector 6 Mun. Bucureşti" become "București" and "Sector 6").
 *
 * The downstream resolvers already normalize at read time, so this is not what
 * makes the competent court or the stamp-duty town hall work. It matters because
 * the stored value is what the lawyer reads in the wizard and what gets printed
 * into the somaţie and the cerere de OP, where ANAF's administrative decoration
 * reads as sloppy drafting.
 *
 * A value with no row in the nomenclature is passed through untouched rather
 * than blanked, so the outcome is never worse than the raw input.
 */
final class AdministrativeUnitResolver
{
    public function __construct(
        private CountyRepository $countyRepository,
        private CityRepository $cityRepository,
    ) {}

    public function resolve(?string $county, ?string $locality): CanonicalLocation
    {
        $normalizedCounty = RomanianAddressNormalizer::normalizeCounty($county);
        $normalizedLocality = RomanianAddressNormalizer::normalizeLocality($locality, $normalizedCounty);

        // Extracting a Bucharest sector is gated on knowing the county first,
        // so a missing or mistyped county hides the sector. When the locality
        // still looks like one, retry with Bucharest before giving up.
        if ($normalizedCounty === null && $normalizedLocality !== null
            && preg_match('/\bsector(?:ul)?\s*[1-6]\b/u', $normalizedLocality) === 1
        ) {
            $normalizedCounty = 'bucuresti';
            $normalizedLocality = RomanianAddressNormalizer::normalizeLocality($locality, $normalizedCounty);
        }

        $countyRow = $normalizedCounty !== null
            ? $this->countyRepository->findOneByNormalizedName($normalizedCounty)
            : null;

        $cityRow = ($countyRow !== null && $normalizedLocality !== null)
            ? $this->cityRepository->findOneByCountyAndNormalizedName($countyRow, $normalizedLocality)
            : null;

        return new CanonicalLocation(
            countyName: $countyRow?->getName() ?? $this->passthrough($county),
            localityName: $cityRow?->getName() ?? $this->passthrough($locality),
            countyMatched: $countyRow !== null,
            localityMatched: $cityRow !== null,
        );
    }

    private function passthrough(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
