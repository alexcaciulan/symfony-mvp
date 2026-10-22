<?php

declare(strict_types=1);

namespace App\Service\Court;

use App\Entity\CourtEmailAddress;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailStatus;
use App\Enum\CourtFilingAddressMissingReason;
use App\Enum\CourtType;
use App\Enum\PersonType;
use App\Repository\CourtEmailAddressRepository;
use Psr\Clock\ClockInterface;

/**
 * The confirmed address of the case's court that a filing would go to. Only a
 * confirmed, unexpired address of a filing purpose is ever returned. A tribunal
 * routes filings by section, so without a confirmed section for these parties the
 * answer is "unknown", never the registry: a wrong section means redistribution
 * or delay that nobody would notice.
 */
final class CourtFilingAddressResolver
{
    private const JUDECATORIE_ORDER = [
        CourtEmailPurpose::REGISTRY,
        CourtEmailPurpose::SECTION_CIVIL,
        CourtEmailPurpose::SECTION_PROFESSIONALS,
    ];

    public function __construct(
        private readonly CourtEmailAddressRepository $addresses,
        private readonly ClockInterface $clock,
    ) {}

    public function resolve(LegalCase $case): CourtFilingAddressResult
    {
        $court = $case->getCourt();
        if ($court === null) {
            return CourtFilingAddressResult::missing(CourtFilingAddressMissingReason::NO_COURT);
        }

        $isTribunal = $court->getType() === CourtType::TRIBUNAL;
        if ($isTribunal && !$this->allDebtorsAreLegalEntities($case)) {
            // A natural person may still be a professional (PFA, II, IF): the section is not derivable.
            return CourtFilingAddressResult::missing(CourtFilingAddressMissingReason::TRIBUNAL_SECTION_UNKNOWN);
        }
        $purposes = $isTribunal ? [CourtEmailPurpose::SECTION_PROFESSIONALS] : self::JUDECATORIE_ORDER;

        $now = $this->clock->now();
        $all = $this->addresses->findForCourt($court);
        $candidates = array_values(array_filter(
            $all,
            static fn (CourtEmailAddress $a): bool => in_array($a->getPurpose(), $purposes, true),
        ));

        foreach ($purposes as $purpose) {
            foreach ($candidates as $address) {
                if ($address->getPurpose() === $purpose && $address->isFilingDefault() && $address->isUsableForFiling($now)) {
                    return CourtFilingAddressResult::found($address);
                }
            }
        }

        $requiredPurpose = $isTribunal ? CourtEmailPurpose::SECTION_PROFESSIONALS : null;
        $validated = array_filter($candidates, static fn (CourtEmailAddress $a): bool => $a->getStatus() === CourtEmailStatus::VALIDATED);
        $reason = match (true) {
            $this->any($validated, static fn (CourtEmailAddress $a): bool => $a->isValidationExpired($now)) => CourtFilingAddressMissingReason::VALIDATION_EXPIRED,
            $validated !== [] => CourtFilingAddressMissingReason::NO_FILING_DEFAULT,
            $this->any($candidates, static fn (CourtEmailAddress $a): bool => $a->getStatus() === CourtEmailStatus::PROPOSED) => CourtFilingAddressMissingReason::NOT_VALIDATED,
            // The registry is never a fallback for a tribunal section.
            $isTribunal && $all !== [] => CourtFilingAddressMissingReason::TRIBUNAL_SECTION_UNKNOWN,
            default => CourtFilingAddressMissingReason::NO_ADDRESS,
        };

        return CourtFilingAddressResult::missing($reason, $requiredPurpose);
    }

    private function allDebtorsAreLegalEntities(LegalCase $case): bool
    {
        $debtors = $case->getDebtors();
        if ($debtors->isEmpty()) {
            return false;
        }

        return $debtors->forAll(static fn (int $_k, LegalCaseDebtor $d): bool => $d->getPersonType() === PersonType::PJ);
    }

    /**
     * @param array<CourtEmailAddress>            $addresses
     * @param callable(CourtEmailAddress): bool   $predicate
     */
    private function any(array $addresses, callable $predicate): bool
    {
        foreach ($addresses as $address) {
            if ($predicate($address)) {
                return true;
            }
        }

        return false;
    }
}
