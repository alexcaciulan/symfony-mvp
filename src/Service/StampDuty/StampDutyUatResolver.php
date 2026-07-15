<?php

declare(strict_types=1);

namespace App\Service\StampDuty;

use App\DTO\StampDuty\StampDutyPaymentTarget;
use App\Entity\LegalCase;
use App\Enum\StampDutyTargetStatus;
use App\Repository\CityRepository;
use App\Service\Court\LocalityNormalizer;

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

        $county = LocalityNormalizer::normalize($creditor?->getAddressCounty());
        $locality = LocalityNormalizer::normalize($creditor?->getAddressLocality());

        if ($county === null || $locality === null) {
            return new StampDutyPaymentTarget(StampDutyTargetStatus::LOCATION_MISSING, courtName: $courtName);
        }

        $uat = $this->cityRepository->findOneByCountyNameAndNormalizedName($county, $locality);

        // ANAF reports the village, not the UAT that collects the duty: a company seated
        // in Dancu comes back as "Sat Dancu Com. Holboca", while the budget account
        // belongs to the commune of Holboca. Without this, every rural registered office
        // would land on "locality unknown" and force the lawyer to correct it by hand.
        if ($uat === null) {
            $commune = $this->communeFromVillageAddress($locality);
            if ($commune !== null) {
                $uat = $this->cityRepository->findOneByCountyNameAndNormalizedName($county, $commune);
            }
        }

        if ($uat === null) {
            return new StampDutyPaymentTarget(StampDutyTargetStatus::UNMATCHED, courtName: $courtName);
        }

        return new StampDutyPaymentTarget(StampDutyTargetStatus::RESOLVED, $uat, $courtName);
    }

    /**
     * Pulls the commune out of an ANAF-style village address ("sat dancu com. holboca"
     * → "holboca"). Returns null when the value carries no commune marker, so a plain
     * town name is never mangled.
     */
    private function communeFromVillageAddress(string $normalizedLocality): ?string
    {
        if (preg_match('/\bcom(?:\.|una)?\s+(.+)$/u', $normalizedLocality, $matches) !== 1) {
            return null;
        }

        $commune = trim($matches[1]);

        return $commune !== '' ? $commune : null;
    }
}
