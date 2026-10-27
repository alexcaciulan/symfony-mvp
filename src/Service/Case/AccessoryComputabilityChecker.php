<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Validation\AdmissibilityIssue;
use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Enum\IssueSeverity;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Service\Calculation\ClaimInterestAggregator;
use App\Service\Calculation\InterestCalculatorService;

/**
 * Finds the claim positions whose accessory the platform has to compute and
 * cannot. The summons states the accessory of every position it claims (CPC
 * art. 1.015 together with art. 1.017 for the petition that follows), so one
 * it cannot quantify would go out with the interest wording and no sum.
 *
 * A contractual penalty with no daily rate is not computed by the platform
 * at all; leaving it unclaimed is the lawyer's choice, not a failure, so such
 * a case has nothing to report here.
 */
final class AccessoryComputabilityChecker
{
    public const CODE = 'ACCESSORY_NOT_COMPUTABLE';

    private const MESSAGE_PREFIX = 'validation.accessory_not_computable.';

    public function __construct(
        private readonly ClaimInterestAggregator $aggregator,
        private readonly InterestCalculatorService $interestCalculator,
    ) {}

    /** @return list<AdmissibilityIssue> one per position that cannot be computed */
    public function issues(LegalCase $case, \DateTimeImmutable $referenceDate): array
    {
        if (!$this->platformComputes($case)) {
            return [];
        }

        $items = $case->getCountingClaimItems();

        return $items === []
            ? $this->caseLevelIssues($case, $referenceDate)
            : $this->itemIssues($case, $items, $referenceDate);
    }

    private function platformComputes(LegalCase $case): bool
    {
        if ($case->getPenaltyType() !== PenaltyType::CONTRACTUAL) {
            return true;
        }

        $rate = $case->getContractualPenaltyRate();

        return $rate !== null && (float) $rate > 0.0;
    }

    /**
     * @param list<ClaimItem> $items
     * @return list<AdmissibilityIssue>
     */
    private function itemIssues(LegalCase $case, array $items, \DateTimeImmutable $referenceDate): array
    {
        $rate = $case->getContractualPenaltyRate();

        try {
            $result = $this->aggregator->aggregate(
                items: $items,
                referenceDate: $referenceDate,
                relationshipType: $case->getRelationshipType() ?? RelationshipType::COMERCIAL,
                penaltyType: $case->getPenaltyType() ?? PenaltyType::LEGAL_PENALIZATOARE,
                contractualDailyRate: $rate !== null ? (float) $rate : null,
                contractualPenaltyCapPercent: $case->contractualPenaltyCap(),
                contractDate: $case->contractDateImmutable(),
            );
        } catch (\DomainException) {
            return [$this->issue('unsupported_relationship', null)];
        }

        $issues = [];
        foreach ($items as $index => $item) {
            // A credit note and a settled balance accrue nothing, by design.
            if ($item->isCreditNote() || ($item->signedAmountRon() ?? 0.0) <= 0.0) {
                continue;
            }

            $position = $this->positionLabel($index, $item->getDocumentNumber());
            if ($item->getDueDate() === null) {
                $issues[] = $this->issue('due_date_missing', $position);

                continue;
            }

            // Same key the aggregator uses, so unsaved positions still match.
            $reason = $result->skipReasonByItemId[$item->getId() ?? -($index + 1)] ?? null;
            if ($reason !== null) {
                $issues[] = $this->issue($this->reasonCode($reason), $position);
            }
        }

        return $issues;
    }

    /**
     * A case saved before positions existed carries one sum and one due date.
     *
     * @return list<AdmissibilityIssue>
     */
    private function caseLevelIssues(LegalCase $case, \DateTimeImmutable $referenceDate): array
    {
        if ((float) ($case->getAmount() ?? '0') <= 0.0) {
            return [];
        }

        $dueDate = $case->getDueDate();
        if ($dueDate === null) {
            return [$this->issue('due_date_missing', null)];
        }

        // The contractual penalty runs on the contract's rate and needs only the
        // due date checked above.
        if ($case->getPenaltyType() === PenaltyType::CONTRACTUAL) {
            return [];
        }

        try {
            $this->interestCalculator->calculate(
                amount: (float) $case->getAmount(),
                dueDate: \DateTimeImmutable::createFromInterface($dueDate),
                referenceDate: $referenceDate,
                relationshipType: $case->getRelationshipType() ?? RelationshipType::COMERCIAL,
                currency: $case->getCurrency(),
                contractDate: $case->contractDateImmutable(),
            );
        } catch (\DomainException) {
            return [$this->issue('unsupported_relationship', null)];
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return [$this->issue($this->reasonCode($e->getMessage()), null)];
        }

        return [];
    }

    private function reasonCode(string $calculatorMessage): string
    {
        return match ($calculatorMessage) {
            'exception.calculation.interest_rate_missing' => 'due_date_before_rate_history',
            'exception.calculation.currency_unsupported', 'exception.calculation.exchange_rate_missing' => 'exchange_rate_missing',
            default => 'undetermined',
        };
    }

    /**
     * Position number in the claim, with the document number when there is
     * one: data, not prose, so it reads the same in every locale.
     */
    private function positionLabel(int $index, ?string $documentNumber): string
    {
        $number = $documentNumber !== null ? trim($documentNumber) : '';

        return $number !== '' ? sprintf('%d (%s)', $index + 1, $number) : (string) ($index + 1);
    }

    private function issue(string $reason, ?string $position): AdmissibilityIssue
    {
        return new AdmissibilityIssue(
            IssueSeverity::ERROR,
            self::CODE,
            self::MESSAGE_PREFIX . ($position !== null ? 'position.' : 'claim.') . $reason,
            $position !== null ? ['%position%' => $position] : [],
        );
    }
}
