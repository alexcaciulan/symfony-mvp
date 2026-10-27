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
 * or delay that nobody would notice. The professionals' section is derived only
 * when every party is a professional by nature: a legal person registered with
 * the trade register as a company (ONRC number starting with J). Not every legal
 * person is a professional (associations, foundations, owners' associations,
 * public authorities), and a natural person registered as PFA, II or IF is one by
 * law but is not inferred here: the lawyer confirms the section.
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
        if ($isTribunal && !$this->allPartiesAreProfessionalsByNature($case)) {
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

    private function allPartiesAreProfessionalsByNature(LegalCase $case): bool
    {
        $creditor = $case->getCreditor();
        $debtors = $case->getDebtors();
        if ($creditor === null || $debtors->isEmpty()
            || !self::isCompany($creditor->getPersonType(), $creditor->getOnrcNumber())) {
            return false;
        }

        return $debtors->forAll(static fn (int $_k, LegalCaseDebtor $d): bool => self::isCompany($d->getPersonType(), $d->getOnrcNumber()));
    }

    private static function isCompany(PersonType $type, ?string $onrcNumber): bool
    {
        return $type === PersonType::PJ && str_starts_with(strtoupper(trim((string) $onrcNumber)), 'J');
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
