<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\DTO\Calculation\CurrencyConversionResult;
use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\PenaltyResult;
use App\DTO\Calculation\StampDutyResult;
use App\DTO\Court\CourtResolveResult;
use App\DTO\Validation\AdmissibilityIssue;
use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step3ClaimData;
use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Enum\IssueSeverity;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Service\Calculation\ClaimInterestAggregator;
use App\Service\Calculation\ContractualPenaltyCalculator;
use App\Service\Calculation\CurrencyConverter;
use App\Service\Calculation\InterestCalculatorService;
use App\Service\Calculation\StampDutyCalculator;
use App\Service\Court\CompetentCourtResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The figures the wizard shows before the case exists: the RON principal, the
 * accessories, the stamp duty and the competent court, each computed
 * defensively so an incomplete step renders placeholders instead of failing.
 */
final class WizardFigures
{
    public function __construct(
        private readonly CurrencyConverter $currencyConverter,
        private readonly InterestCalculatorService $interestCalculator,
        private readonly ContractualPenaltyCalculator $penaltyCalculator,
        private readonly CompetentCourtResolver $courtResolver,
        private readonly StampDutyCalculator $stampDutyCalculator,
        private readonly ClaimInterestAggregator $accessoryAggregator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Now, or the earlier date the lawyer chose to compute the accessories up
     * to: the rule the saved case applies through
     * {@see LegalCase::accessoryReferenceDate()}.
     */
    public function accessoryReferenceDate(Step3ClaimData $claim): \DateTimeImmutable
    {
        return LegalCase::accessoryDateWithin($claim->accessoryCutoffDate, new \DateTimeImmutable());
    }

    /**
     * FX conversion for a foreign-currency claim, at the BNR rate of the invoice
     * emission date. Null when the claim is already in RON, when required inputs
     * are missing, or when no BNR rate is available (logged, surfaced upstream).
     */
    public function resolveConversion(Step3ClaimData $claim): ?CurrencyConversionResult
    {
        if ($claim->amount === null || $claim->currency === 'RON' || $claim->invoiceDate === null) {
            return null;
        }

        try {
            return $this->currencyConverter->convertToRon($claim->amount, $claim->currency, $claim->invoiceDate);
        } catch (\RuntimeException $e) {
            $this->logger->info('wizard.calc.fx_failed', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The claim amount every calculation runs on: the RON-converted value for a
     * foreign currency, or the raw amount for RON. Null signals "cannot compute"
     * (foreign currency without a resolvable rate).
     */
    public function ronAmount(Step3ClaimData $claim, ?CurrencyConversionResult $conversion): ?float
    {
        if ($claim->currency === 'RON') {
            return $claim->amount;
        }

        return $conversion?->ronAmount;
    }

    /**
     * @param list<AdmissibilityIssue> $issues
     * @return array{errors: list<AdmissibilityIssue>, warnings: list<AdmissibilityIssue>}
     */
    public function splitIssues(array $issues): array
    {
        $errors = [];
        $warnings = [];
        foreach ($issues as $issue) {
            if ($issue->severity === IssueSeverity::ERROR) {
                $errors[] = $issue;
            } else {
                $warnings[] = $issue;
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Computes the sidebar figures defensively: nulls on any calculation
     * failure (missing BNR rate, county unknown, DomainException), so the
     * template renders placeholders instead of failing the page when the user
     * is on step 3 or 4 with incomplete data.
     *
     * @return array{interest: ?InterestResult, penalty: ?PenaltyResult, accessoryTotal: float, stampDuty: ?StampDutyResult, court: ?CourtResolveResult, conversion: ?CurrencyConversionResult, accessoryPerItem: ?AggregatedAccessoryResult, claimItems: list<ClaimItem>, principal: ?float}
     */
    public function safeComputeForSidebar(
        Step3ClaimData $claim,
        ?Step2DebtorEntry $primaryDebtor,
        ?CurrencyConversionResult $conversion,
        ?LegalCase $skeleton = null,
    ): array {
        $items = $skeleton?->getCountingClaimItems() ?? [];
        if ($items !== [] && $claim->relationshipType !== null) {
            return $this->computeFromItems($items, $claim, $primaryDebtor, $conversion);
        }

        // Everything downstream runs on RON: convert a foreign-currency claim
        // first. A foreign currency with no resolvable rate yields a null
        // principal, so we bail to placeholders (and the template shows the FX
        // error via `conversion`).
        $principal = $this->ronAmount($claim, $conversion);
        if ($principal === null || $principal <= 0.0 || $claim->dueDate === null || $claim->relationshipType === null) {
            return ['interest' => null, 'penalty' => null, 'accessoryTotal' => 0.0, 'stampDuty' => $this->safeStampDuty(), 'court' => null, 'conversion' => $conversion, 'accessoryPerItem' => null, 'claimItems' => [], 'principal' => null];
        }

        $now = $this->accessoryReferenceDate($claim);

        // Accessory routing: contractual penalty (daily rate) vs. legal penalty
        // interest (OG 13/2011, NBR + 4 or + 8 by contract date). Both feed the
        // same `calculatedInterest` slot at persist; the document recomputes
        // from penaltyType at render.
        $interest = null;
        $penalty = null;
        $accessoryTotal = 0.0;

        if ($claim->penaltyType === PenaltyType::CONTRACTUAL && $claim->contractualPenaltyRate !== null) {
            $penalty = $this->penaltyCalculator->calculate(
                $principal,
                $claim->contractualPenaltyRate,
                $claim->dueDate,
                $now,
                $claim->contractualPenaltyCapPercent,
            );
            $accessoryTotal = $penalty->total;
        } else {
            try {
                $interest = $this->interestCalculator->calculate(
                    $principal,
                    $claim->dueDate,
                    $now,
                    $claim->relationshipType,
                    contractDate: $claim->contractDate,
                );
                $accessoryTotal = $interest->total;
            } catch (\DomainException|\RuntimeException $e) {
                $this->logger->info('wizard.calc.interest_failed', ['reason' => $e->getMessage()]);
                $interest = null;
            }
        }

        // County and locality come from the structured primary debtor entry
        // (ANAF lookup, AI extraction, or manual). When absent the resolver
        // returns court=null with `court.resolver.county_unknown` and the user
        // picks the court manually at step 4; the submit still proceeds.
        //
        // Competence is decided on the principal alone (CPC art. 98 alin. 2,
        // accessories excluded); the accessory amount below feeds only the
        // displayed breakdown and the petit.
        $isContractual = $claim->penaltyType === PenaltyType::CONTRACTUAL;
        try {
            $court = $this->courtResolver->resolve(
                $principal,
                $claim->dueDate,
                $now,
                $claim->relationshipType,
                debtorCounty: $primaryDebtor?->addressCounty,
                debtorLocality: $primaryDebtor?->addressLocality,
                scadentPenalties: $isContractual ? $accessoryTotal : 0.0,
                computeLegalInterest: !$isContractual,
                contractDate: $claim->contractDate,
            );
        } catch (\DomainException|\RuntimeException $e) {
            $this->logger->info('wizard.calc.court_failed', ['reason' => $e->getMessage()]);
            $court = null;
        }

        return ['interest' => $interest, 'penalty' => $penalty, 'accessoryTotal' => $accessoryTotal, 'stampDuty' => $this->safeStampDuty(), 'court' => $court, 'conversion' => $conversion, 'accessoryPerItem' => null, 'claimItems' => [], 'principal' => $principal];
    }

    /**
     * The same figures, computed from the claim positions: each accrues from its
     * own due date, and the competent court follows CPC art. 99 over the causes
     * the positions rest on.
     *
     * @param  list<ClaimItem> $items
     * @return array{interest: ?InterestResult, penalty: ?PenaltyResult, accessoryTotal: float, stampDuty: ?StampDutyResult, court: ?CourtResolveResult, conversion: ?CurrencyConversionResult, accessoryPerItem: ?AggregatedAccessoryResult, claimItems: list<ClaimItem>, principal: ?float}
     */
    private function computeFromItems(
        array $items,
        Step3ClaimData $claim,
        ?Step2DebtorEntry $primaryDebtor,
        ?CurrencyConversionResult $conversion,
    ): array {
        $now = $this->accessoryReferenceDate($claim);
        $relationshipType = $claim->relationshipType ?? RelationshipType::COMERCIAL;
        $penaltyType = $claim->penaltyType ?? PenaltyType::LEGAL_PENALIZATOARE;

        try {
            $accessory = $this->accessoryAggregator->aggregate(
                items: $items,
                referenceDate: $now,
                relationshipType: $relationshipType,
                penaltyType: $penaltyType,
                contractualDailyRate: $claim->contractualPenaltyRate,
                contractualPenaltyCapPercent: $claim->contractualPenaltyCapPercent,
                contractDate: $claim->contractDate,
            );
        } catch (\DomainException | \RuntimeException $e) {
            // An unsupported claim type reaches here now instead of turning into
            // a zero; the step renders placeholders rather than a wrong figure.
            $this->logger->info('wizard.calc.items_accessory_failed', ['reason' => $e->getMessage()]);
            $accessory = new AggregatedAccessoryResult(0.0);
        }

        // A single position is the case as it always was, so the sidebar still
        // gets the one result it knows how to break down by period.
        $interest = null;
        $penalty = null;
        if (count($items) === 1) {
            $only = $accessory->forItem($items[0]->getId() ?? -1);
            $interest = $only instanceof InterestResult ? $only : null;
            $penalty = $only instanceof PenaltyResult ? $only : null;
        }

        try {
            $court = $this->courtResolver->resolveForItems(
                items: $items,
                referenceDate: $now,
                relationshipType: $relationshipType,
                debtorCounty: $primaryDebtor?->addressCounty,
                debtorLocality: $primaryDebtor?->addressLocality,
                penaltyType: $penaltyType,
                contractualDailyRate: $claim->contractualPenaltyRate,
                contractualPenaltyCapPercent: $claim->contractualPenaltyCapPercent,
                contractDate: $claim->contractDate,
            );
        } catch (\DomainException | \RuntimeException $e) {
            $this->logger->info('wizard.calc.court_failed', ['reason' => $e->getMessage()]);
            $court = null;
        }

        return [
            'interest' => $interest,
            'penalty' => $penalty,
            'accessoryTotal' => $accessory->total,
            'stampDuty' => $this->safeStampDuty(),
            'court' => $court,
            'conversion' => $conversion,
            'accessoryPerItem' => $accessory,
            'claimItems' => $items,
            // What the positions actually add up to. The step 3 scalar is one
            // document's figure; persisting is done from this one, so the
            // confirmation screen has to show this one.
            'principal' => $this->itemsPrincipal($items),
        ];
    }

    /**
     * @param list<ClaimItem> $items
     */
    private function itemsPrincipal(array $items): float
    {
        $principal = 0.0;
        foreach ($items as $item) {
            $principal += $item->signedAmountRon() ?? 0.0;
        }

        return round($principal, 2);
    }

    private function safeStampDuty(): ?StampDutyResult
    {
        try {
            return $this->stampDutyCalculator->calculate();
        } catch (\Throwable $e) {
            $this->logger->info('wizard.calc.stamp_duty_failed', ['reason' => $e->getMessage()]);

            return null;
        }
    }
}
