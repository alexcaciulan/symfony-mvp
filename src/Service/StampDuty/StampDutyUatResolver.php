<?php

declare(strict_types=1);

namespace App\Service\StampDuty;

use App\DTO\StampDuty\StampDutyPaymentTarget;
use App\Entity\LegalCase;
use App\Enum\StampDutyTargetStatus;
use App\Repository\CityRepository;
use App\Service\Court\RomanianAddressNormalizer;

/**
 * Resolves the UAT whose local budget collects the judicial stamp duty: the one
 * where the CLAIMANT has its registered office (OUG 80/2013 art. 40 alin. 1), not
 * the court's and not the debtor's.
 */
final class StampDutyUatResolver
{
    /** Distinct local-budget revenue account the duty must land in (art. 40 alin. 1). */
    public const ACCOUNT_NAME = 'Taxe judiciare de timbru și alte taxe de timbru';

    public function __construct(
        private readonly CityRepository $cityRepository,
    ) {}

    public function resolve(LegalCase $case): StampDutyPaymentTarget
    {
        $creditor = $case->getCreditor();
        $courtName = $case->getCourt()?->getName();

        // Both ANAF and the AI extraction feed this field, and they decorate the
        // registered office differently: ANAF answers "MUNICIPIUL BUCUREŞTI" /
        // "Sector 6 Mun. Bucureşti", the AI returns bare "București" / "Sector 6".
        // RomanianAddressNormalizer reconciles either spelling with the SIRUTA
        // nomenclature (strips county qualifiers, extracts the Bucharest sector,
        // and resolves an ANAF village like "Sat Dancu Com. Holboca" to its
        // commune, the UAT that actually collects the duty per art. 40 alin. 1).
        $county = RomanianAddressNormalizer::normalizeCounty($creditor?->getAddressCounty());
        $locality = RomanianAddressNormalizer::normalizeLocality($creditor?->getAddressLocality(), $county);

        if ($county === null || $locality === null) {
            return new StampDutyPaymentTarget(StampDutyTargetStatus::LOCATION_MISSING, courtName: $courtName);
        }

        $uat = $this->cityRepository->findOneByCountyNameAndNormalizedName($county, $locality);

        if ($uat === null) {
            return new StampDutyPaymentTarget(StampDutyTargetStatus::UNMATCHED, courtName: $courtName);
        }

        return new StampDutyPaymentTarget(StampDutyTargetStatus::RESOLVED, $uat, $courtName);
    }
}
