<?php

namespace App\Service\Court;

use App\DTO\Court\ClaimValueBreakdown;
use App\DTO\Court\CourtResolveResult;
use App\Entity\ClaimItem;
use App\Entity\Court;
use App\Enum\CourtType;
use App\Enum\InterestKind;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Repository\CourtRepository;
use App\Service\Calculation\ClaimInterestAggregator;
use App\Service\Calculation\ContractualPenaltyCalculator;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Claim\ClaimCauseGrouper;

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
        private ?ClaimInterestAggregator $accessoryAggregator = null,
        private ?ClaimCauseGrouper $causeGrouper = null,
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

        // Competence is decided on the principal alone (CPC art. 98 alin. (2):
        // accessories excluded regardless of due date). $total drives display
        // and the petit, not the routing.
        $type = $this->courtTypeFor($principal);

        return $type === CourtType::TRIBUNAL
            ? $this->resolveTribunal($debtorCounty, $breakdown)
            : $this->resolveLocalCourt($debtorCounty, $debtorLocality, $breakdown);
    }

    /**
     * Competence over a set of claim positions, CPC art. 99.
     *
     * Art. 98 alin. (2), already applied by {@see resolve()}, only says that
     * accessories are left out of the valuation. It is art. 99 that says how
     * several principal heads are valued:
     *   alin. (1): heads resting on DIFFERENT facts or causes are each valued on
     *              their own, with disjunction and declining where they diverge;
     *   alin. (2): heads resting on a common title or the same cause (or on
     *              causes in close connection) are valued together, competence
     *              following the head that draws the higher court.
     *
     * The difference decides the court. Five invoices of 60.000 RON out of five
     * separate contracts total 300.000 and would route to the tribunal on a
     * cumulative reading, while art. 99 alin. (1) values each at 60.000 and
     * leaves them with the judecătorie. Filing at the wrong one draws a plea of
     * material incompetence, which is of public order and raised ex officio.
     *
     * Within one cause the positions are summed rather than compared. That is a
     * substantive choice and it is deliberate: the petition this application
     * generates formulates a single principal head out of the invoices issued
     * under one contract, so its value is their total (art. 98 alin. 1), and
     * art. 99 alin. (2) then reads on that single head.
     *
     * Positions carrying no stated cause join the file's single stated cause
     * when there is exactly one, and form a cause of their own when there is
     * none. Valuing an unlabelled invoice apart from the contract every other
     * invoice on the file names is not conservative, it quietly demotes the
     * court on a partial extraction.
     *
     * When the causes are different and they do not all land at the same court
     * type, or when unlabelled positions cannot be attributed because the file
     * states several causes, no court is returned: the divergence means separate
     * petitions, and picking either one for the lawyer would be the very mistake
     * this guards against.
     *
     * @param iterable<ClaimItem> $items positions that count towards the claim
     */
    public function resolveForItems(
        iterable $items,
        \DateTimeImmutable $referenceDate,
        RelationshipType $relationshipType,
        ?string $debtorCounty,
        ?string $debtorLocality = null,
        InterestKind $interestKind = InterestKind::PENALIZATOARE,
        PenaltyType $penaltyType = PenaltyType::LEGAL_PENALIZATOARE,
        ?float $contractualDailyRate = null,
    ): CourtResolveResult {
        $counting = [];
        foreach ($items as $item) {
            $counting[] = $item;
        }

        [$principalByCause, $causesUncertain] = $this->principalByCause($counting);
        $principal = round(array_sum($principalByCause), 2);
        $isContractual = $penaltyType === PenaltyType::CONTRACTUAL
            && $contractualDailyRate !== null
            && $contractualDailyRate > 0.0;

        $accessory = $this->aggregator()->aggregate(
            items: $counting,
            referenceDate: $referenceDate,
            relationshipType: $relationshipType,
            penaltyType: $penaltyType,
            contractualDailyRate: $contractualDailyRate,
            kind: $interestKind,
        );
        $accruedInterest = $isContractual ? 0.0 : $accessory->total;
        $scadentPenalties = $isContractual ? $accessory->total : 0.0;

        if ($principal < 0.0) {
            return $this->emptyResult($principal, $accruedInterest, $scadentPenalties, 'court.resolver.invalid_amount_negative');
        }
        if ($principal === 0.0) {
            return $this->emptyResult($principal, $accruedInterest, $scadentPenalties, 'court.resolver.invalid_amount_zero');
        }
        if ($debtorCounty === null || trim($debtorCounty) === '') {
            return $this->emptyResult($principal, $accruedInterest, $scadentPenalties, 'court.resolver.county_unknown');
        }

        $breakdown = new ClaimValueBreakdown(
            $principal,
            $accruedInterest,
            $scadentPenalties,
            $principal + $accruedInterest + $scadentPenalties,
        );

        $types = [];
        foreach ($principalByCause as $causePrincipal) {
            $types[$this->courtTypeFor($causePrincipal)->value] = true;
        }

        if ($causesUncertain || count($types) > 1) {
            return new CourtResolveResult(
                null,
                [],
                $causesUncertain
                    ? 'court.resolver.art99_cause_unattributable'
                    : 'court.resolver.art99_divergent_competence',
                $breakdown,
                ['%causes%' => (string) count($principalByCause)],
            );
        }

        $type = $this->courtTypeFor((float) max($principalByCause));

        return $type === CourtType::TRIBUNAL
            ? $this->resolveTribunal($debtorCounty, $breakdown)
            : $this->resolveLocalCourt($debtorCounty, $debtorLocality, $breakdown);
    }

    /**
     * Principal per cause, plus whether the grouping itself is uncertain.
     *
     * Delegates to the shared {@see ClaimCauseGrouper} so the petition's art. 99
     * note is decided on the same grouping that routes the file here. Uncertain
     * means the file names several causes AND carries positions that name none,
     * so those cannot be attributed to any of them: guessing either way changes
     * the court, so the caller declines to pick one.
     *
     * @param  list<ClaimItem> $items
     * @return array{0: array<string, float>, 1: bool}
     */
    private function principalByCause(array $items): array
    {
        return $this->causeGrouper()->group($items);
    }

    private function causeGrouper(): ClaimCauseGrouper
    {
        return $this->causeGrouper ??= new ClaimCauseGrouper();
    }

    private function courtTypeFor(float $principal): CourtType
    {
        return $principal > self::JURISDICTION_THRESHOLD_RON ? CourtType::TRIBUNAL : CourtType::JUDECATORIE;
    }

    private function aggregator(): ClaimInterestAggregator
    {
        return $this->accessoryAggregator ??= new ClaimInterestAggregator(
            $this->interestCalculator,
            new ContractualPenaltyCalculator(),
        );
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
            return new CourtResolveResult(null, array_values($specialized), 'court.resolver.tribunal_ambiguous', $breakdown, ['%county%' => $county]);
        }

        $candidates = $this->courtRepository->findActiveByTypeAndCounty(CourtType::TRIBUNAL, $county);

        if (count($candidates) === 0) {
            return new CourtResolveResult(null, [], 'court.resolver.tribunal_missing', $breakdown, ['%county%' => $county]);
        }
        if (count($candidates) > 1) {
            return new CourtResolveResult(null, array_values($candidates), 'court.resolver.tribunal_ambiguous', $breakdown, ['%county%' => $county]);
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
            return new CourtResolveResult(null, [], 'court.resolver.no_judecatorie_in_county', $breakdown, ['%county%' => $county]);
        }

        // Normalized in the county's context: Bucharest localities carry the
        // sector, which is what `city` actually stores there.
        $normalizedLocality = RomanianAddressNormalizer::normalizeLocality(
            $locality,
            RomanianAddressNormalizer::normalizeCounty($county),
        );
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
