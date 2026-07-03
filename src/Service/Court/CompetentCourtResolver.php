<?php

namespace App\Service\Court;

use App\DTO\Court\ClaimValueBreakdown;
use App\DTO\Court\CourtResolveResult;
use App\Entity\Court;
use App\Enum\CourtType;
use App\Enum\InterestKind;
use App\Enum\RelationshipType;
use App\Repository\CourtRepository;
use App\Service\Calculation\InterestCalculatorService;

final class CompetentCourtResolver
{
    /**
     * Judecătorie/tribunal threshold for OP claims (CPC art. 94 pct. 1 lit. k + art. 95 pct. 1).
     * Applied on the principal only: CPC art. 98 alin. (2) excludes accessories from the
     * competence valuation. ClaimValueBreakdown keeps the full total for display, not routing.
     */
    private const JURISDICTION_THRESHOLD_RON = 200_000.0;

    public function __construct(
        private CourtRepository $courtRepository,
        private InterestCalculatorService $interestCalculator,
    ) {}

    /**
     * @param bool $computeLegalInterest When true (default) legal interest
     *        (OG 13/2011) is computed internally and shown in the breakdown. Set
     *        false when the claim's accessory is a contractual penalty (passed via
     *        $scadentPenalties) so the two accessories are not double-counted in
     *        the displayed total: a contractual penalty clause stands in lieu of
     *        legal interest. This affects only the displayed breakdown, never the
     *        competent court (decided on the principal alone, CPC art. 98 alin. 2).
     *
     * @throws \DomainException Propagat din InterestCalculatorService când relationshipType=CIVIL
     *                          (B2B-only MVP per Pas 2.1 revizie C3, fail-fast intentionat).
     * @throws \RuntimeException Când nu există configurație BNR pentru data scadenței
     *                           (`exception.calculation.interest_rate_missing`).
     */
    public function resolve(
        float $principal,
        \DateTimeImmutable $dueDate,
        \DateTimeImmutable $referenceDate,
        RelationshipType $relationshipType,
        ?string $debtorCounty,
        ?string $debtorLocality = null,
        float $scadentPenalties = 0.0,
        InterestKind $interestKind = InterestKind::PENALIZATOARE,
        bool $computeLegalInterest = true,
    ): CourtResolveResult {
        if ($principal < 0.0) {
            return $this->emptyResult($principal, 0.0, 0.0, 'court.resolver.invalid_amount_negative');
        }
        if ($principal === 0.0) {
            return $this->emptyResult($principal, 0.0, $scadentPenalties, 'court.resolver.invalid_amount_zero');
        }
        if ($debtorCounty === null || trim($debtorCounty) === '') {
            $accruedInterest = $computeLegalInterest
                ? $this->interestCalculator
                    ->calculate($principal, $dueDate, $referenceDate, $relationshipType, $interestKind)
                    ->total
                : 0.0;

            return $this->emptyResult($principal, $accruedInterest, $scadentPenalties, 'court.resolver.county_unknown');
        }

        $accruedInterest = $computeLegalInterest
            ? $this->interestCalculator
                ->calculate($principal, $dueDate, $referenceDate, $relationshipType, $interestKind)
                ->total
            : 0.0;

        $total = $principal + $accruedInterest + $scadentPenalties;
        $breakdown = new ClaimValueBreakdown($principal, $accruedInterest, $scadentPenalties, $total);

        // Competence is decided on the principal alone (CPC art. 98 alin. (2) —
        // accessories excluded regardless of due date). $total drives display
        // and the petit, not the routing.
        $type = $principal > self::JURISDICTION_THRESHOLD_RON ? CourtType::TRIBUNAL : CourtType::JUDECATORIE;

        return $type === CourtType::TRIBUNAL
            ? $this->resolveTribunal($debtorCounty, $breakdown)
            : $this->resolveLocalCourt($debtorCounty, $debtorLocality, $breakdown);
    }

    private function resolveTribunal(string $county, ClaimValueBreakdown $breakdown): CourtResolveResult
    {
        // Specialized commercial tribunals (Cluj/Mureș/Argeș, Legea 304/2022 art. 41)
        // take priority over the common tribunal in their county. Data-driven
        // (query specialized first) so a future one needs only a master-data entry.
        $specialized = $this->courtRepository->findActiveByTypeAndCounty(CourtType::TRIBUNAL_SPECIALIZAT, $county);
        if (count($specialized) === 1) {
            return new CourtResolveResult($specialized[0], [], 'court.resolver.matched_tribunal_specializat', $breakdown);
        }
        if (count($specialized) > 1) {
            return new CourtResolveResult(null, array_values($specialized), 'court.resolver.tribunal_ambiguous', $breakdown);
        }

        $candidates = $this->courtRepository->findActiveByTypeAndCounty(CourtType::TRIBUNAL, $county);

        if (count($candidates) === 0) {
            return new CourtResolveResult(null, [], 'court.resolver.tribunal_missing', $breakdown);
        }
        if (count($candidates) > 1) {
            return new CourtResolveResult(null, array_values($candidates), 'court.resolver.tribunal_ambiguous', $breakdown);
        }

        return new CourtResolveResult($candidates[0], [], 'court.resolver.matched_tribunal', $breakdown);
    }

    private function resolveLocalCourt(
        string $county,
        ?string $locality,
        ClaimValueBreakdown $breakdown,
    ): CourtResolveResult {
        $candidates = $this->courtRepository->findActiveByTypeAndCounty(CourtType::JUDECATORIE, $county);

        if (count($candidates) === 0) {
            return new CourtResolveResult(null, [], 'court.resolver.no_judecatorie_in_county', $breakdown);
        }

        $normalizedLocality = LocalityNormalizer::normalize($locality);
        if ($normalizedLocality === null) {
            return new CourtResolveResult(null, array_values($candidates), 'court.resolver.locality_unmatched_pick_manually', $breakdown);
        }

        $matched = array_values(array_filter(
            $candidates,
            fn(Court $c) => $this->courtCoversLocality($c, $normalizedLocality),
        ));

        if (count($matched) === 1) {
            return new CourtResolveResult($matched[0], [], 'court.resolver.matched_judecatorie', $breakdown);
        }
        if (count($matched) >= 2) {
            return new CourtResolveResult(null, $matched, 'court.resolver.locality_ambiguous', $breakdown);
        }

        return new CourtResolveResult(null, array_values($candidates), 'court.resolver.locality_unmatched_pick_manually', $breakdown);
    }

    private function courtCoversLocality(Court $court, string $normalizedLocality): bool
    {
        $covered = $court->getCoveredCityNames();
        if ($covered === []) {
            return false;
        }

        foreach ($covered as $entry) {
            if (LocalityNormalizer::normalize($entry) === $normalizedLocality) {
                return true;
            }
        }

        return false;
    }

    private function emptyResult(
        float $principal,
        float $accruedInterest,
        float $scadentPenalties,
        string $explanationKey,
    ): CourtResolveResult {
        return new CourtResolveResult(
            null,
            [],
            $explanationKey,
            new ClaimValueBreakdown(
                $principal,
                $accruedInterest,
                $scadentPenalties,
                $principal + $accruedInterest + $scadentPenalties,
            ),
        );
    }
}
