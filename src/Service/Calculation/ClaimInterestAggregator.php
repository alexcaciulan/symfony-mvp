<?php

declare(strict_types=1);

namespace App\Service\Calculation;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\Entity\ClaimItem;
use App\Enum\InterestKind;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs the per-claim calculators once per position and adds the results up.
 *
 * The calculators below are correct for a single claim and already segment on
 * NBR rate changes; the defect this fixes is upstream of them, in feeding one
 * calculator the aggregate sum from the earliest due date. Each position is
 * calculated on its own amount from its own due date, which is what Civil Code
 * art. 1535 makes each of them accrue from.
 *
 * Rounding happens once, on the sum. Rounding per position and adding would
 * drift by up to half a bani per invoice against the figure the court re-adds.
 */
final class ClaimInterestAggregator
{
    public function __construct(
        private readonly InterestCalculatorService $interestCalculator,
        private readonly ContractualPenaltyCalculator $penaltyCalculator,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param iterable<ClaimItem> $items positions that count towards the claim
     *
     * @throws \DomainException when the claim type itself is unsupported
     *                          (CIVIL, B2B-only MVP), as on the single-claim path
     */
    public function aggregate(
        iterable $items,
        \DateTimeImmutable $referenceDate,
        RelationshipType $relationshipType,
        PenaltyType $penaltyType = PenaltyType::LEGAL_PENALIZATOARE,
        ?float $contractualDailyRate = null,
        InterestKind $kind = InterestKind::PENALIZATOARE,
    ): AggregatedAccessoryResult {
        $isContractual = $penaltyType === PenaltyType::CONTRACTUAL
            && $contractualDailyRate !== null
            && $contractualDailyRate > 0.0;

        $total = 0.0;
        $interestByItem = [];
        $penaltyByItem = [];
        $skipped = [];

        foreach ($items as $index => $item) {
            $key = $item->getId() ?? -((int) $index + 1);

            // A credit note takes value out of the claim; there is nothing for
            // it to accrue on, and that is by design, not a failure to report.
            if ($item->isCreditNote()) {
                continue;
            }

            $dueDate = $item->getDueDate();
            $amountRon = $item->getAmountRon();
            $amount = $amountRon !== null ? (float) $amountRon : 0.0;

            // No due date means nothing is late yet, and a zero balance has
            // nothing to accrue on. Both are reported, not dropped.
            if ($dueDate === null || $amount <= 0.0) {
                $skipped[] = $key;

                continue;
            }

            if ($isContractual) {
                $result = $this->penaltyCalculator->calculate(
                    amount: $amount,
                    dailyRatePercent: $contractualDailyRate,
                    startDate: $dueDate,
                    referenceDate: $referenceDate,
                );
                $penaltyByItem[$key] = $result;
                $total += $result->total;

                continue;
            }

            try {
                $result = $this->interestCalculator->calculate(
                    amount: $amount,
                    dueDate: $dueDate,
                    referenceDate: $referenceDate,
                    relationshipType: $relationshipType,
                    kind: $kind,
                    currency: 'RON',
                    invoiceDate: $item->getDocumentDate(),
                );
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                // A DomainException is not a position that cannot accrue, it is
                // a claim type the MVP refuses to compute (CIVIL). It has to
                // reach the caller, as it does on the single-claim path, rather
                // than being turned into a plausible-looking zero.
                $this->logger->info('claim.interest.item_skipped', [
                    'reason' => $e->getMessage(),
                    'itemId' => $item->getId(),
                ]);
                $skipped[] = $key;

                continue;
            }

            $interestByItem[$key] = $result;
            $total += $result->total;
        }

        return new AggregatedAccessoryResult(
            total: round($total, 2),
            interestByItemId: $interestByItem,
            penaltyByItemId: $penaltyByItem,
            skippedItemIds: $skipped,
        );
    }
}
