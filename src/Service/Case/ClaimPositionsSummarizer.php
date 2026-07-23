<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Calculation\InterestResult;
use App\DTO\Wizard\ClaimItemRow;
use App\Entity\LegalCase;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use App\Service\Calculation\ClaimInterestAggregator;
use Psr\Log\LoggerInterface;

/**
 * Turns the step 3 claim rows into the figures both the persist path and the
 * live sidebar render: per-row accessory, the BNR-period breakdown behind it,
 * the counting totals, and the earliest due date. Shared so the number the
 * lawyer watches while editing is the same one the submit path computes.
 *
 * @phpstan-type PositionSummary array{
 *     accessoryByRow: array<int, ?float>,
 *     breakdownByRow: array<int, list<\App\DTO\Calculation\InterestPeriod>>,
 *     unavailableRows: list<int>,
 *     principal: float,
 *     accessory: float,
 *     earliestDueDate: ?\DateTimeImmutable,
 *     countedRows: int,
 * }
 */
final class ClaimPositionsSummarizer
{
    public function __construct(
        private readonly ClaimItemFactory $claimItemFactory,
        private readonly ClaimInterestAggregator $accessoryAggregator,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param list<ClaimItemRow> $rows
     * @return PositionSummary
     */
    public function summarize(
        array $rows,
        RelationshipType $relationshipType,
        PenaltyType $penaltyType,
        ?float $contractualDailyRate,
        ?\DateTimeImmutable $referenceDate = null,
    ): array {
        $referenceDate ??= new \DateTimeImmutable();
        $summary = [
            'accessoryByRow' => [],
            'breakdownByRow' => [],
            'unavailableRows' => [],
            'principal' => 0.0,
            'accessory' => 0.0,
            'earliestDueDate' => null,
            'countedRows' => 0,
        ];

        if ($rows === []) {
            return $summary;
        }

        $counting = [];
        foreach ($rows as $index => $row) {
            if (!$row->willCount()) {
                continue;
            }
            $counting[$index] = $row;
            $summary['principal'] += $row->signedAmountRon() ?? 0.0;
            ++$summary['countedRows'];

            $dueDate = $row->dueDate;
            if ($dueDate !== null
                && ($summary['earliestDueDate'] === null || $dueDate < $summary['earliestDueDate'])) {
                $summary['earliestDueDate'] = $dueDate;
            }
        }

        $summary['principal'] = round($summary['principal'], 2);
        if ($counting === []) {
            return $summary;
        }

        // Materialized against a throwaway case: the aggregator works on
        // entities, and these rows are not persisted until step 4.
        $items = $this->claimItemFactory->materialize(new LegalCase(), $rows);
        $countingItems = array_intersect_key($items, $counting);

        try {
            $accessory = $this->accessoryAggregator->aggregate(
                items: $countingItems,
                referenceDate: $referenceDate,
                relationshipType: $relationshipType,
                penaltyType: $penaltyType,
                contractualDailyRate: $contractualDailyRate,
            );
        } catch (\DomainException | \RuntimeException $e) {
            $this->logger->info('wizard.calc.items_accessory_failed', ['reason' => $e->getMessage()]);

            return $summary;
        }

        $summary['accessory'] = $accessory->total;
        foreach ($counting as $index => $row) {
            $result = $accessory->forItem(-($index + 1));
            $summary['accessoryByRow'][$index] = $result?->total;
            // Legal interest carries its own BNR-rate segmentation; keep it per
            // position so the lawyer can see how each invoice's interest was
            // computed, the way the scalar model showed it before positions.
            if ($result instanceof InterestResult) {
                $summary['breakdownByRow'][$index] = $result->breakdown;
            }
            // A position with a due date that produced nothing is a computation
            // that failed, not a position that owes nothing. Say which.
            if ($result === null && $row->dueDate !== null && !$row->isCreditNote()) {
                $summary['unavailableRows'][] = $index;
            }
        }

        return $summary;
    }
}
