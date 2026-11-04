<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Calculation\AggregatedAccessoryResult;
use App\DTO\Calculation\PenaltyBreakdownRow;
use App\DTO\Case\ClaimPositionRow;
use App\DTO\Case\ClaimPositionsView;
use App\Entity\ClaimItem;
use App\Entity\LegalCase;

/**
 * Lays out the claim positions for the claim card: the state of each one, its
 * figures in lei and the accessory the case aggregate computed on it. Nothing
 * is recomputed here; the order is for display only.
 */
final class ClaimPositionsPresenter
{
    /**
     * @param list<int> $addedItemIds positions added by the request being answered
     */
    public function present(LegalCase $case, ?AggregatedAccessoryResult $accessories, \DateTimeImmutable $referenceDate, array $addedItemIds = []): ClaimPositionsView
    {
        $items = $case->getClaimItems()->toArray();
        $countingCount = count(array_filter($items, static fn (ClaimItem $item): bool => $item->countsTowardsClaim()));
        $reference = $referenceDate->setTime(0, 0);

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->row($case, $item, $accessories, $reference, $addedItemIds, $countingCount);
        }

        usort($rows, self::compare(...));

        $excluded = 0;
        $attention = 0;
        $paid = 0.0;
        $foreign = false;
        foreach ($rows as $row) {
            if ($row->isExcluded()) {
                ++$excluded;
            } elseif (!$row->counts()) {
                ++$attention;
            } else {
                $paid += $row->paidRon;
            }
            if (strtoupper($row->item->getCurrency()) !== 'RON') {
                $foreign = true;
            }
        }

        return new ClaimPositionsView(
            rows: $rows,
            countingCount: $countingCount,
            excludedCount: $excluded,
            attentionCount: $attention,
            paidRonTotal: round($paid, 2),
            hasForeignCurrency: $foreign,
        );
    }

    /** @param list<int> $addedItemIds */
    private function row(LegalCase $case, ClaimItem $item, ?AggregatedAccessoryResult $accessories, \DateTimeImmutable $reference, array $addedItemIds, int $countingCount): ClaimPositionRow
    {
        $state = match (true) {
            $item->isExcludedByLawyer() => ClaimPositionRow::STATE_EXCLUDED,
            $item->countsTowardsClaim() => ClaimPositionRow::STATE_COUNTING,
            default => ClaimPositionRow::STATE_PENDING,
        };
        $counts = $state === ClaimPositionRow::STATE_COUNTING;
        $id = $item->getId();
        $rest = $item->signedAmountRon();
        $paidRon = 0.0;
        if ($rest !== null && !$item->isCreditNote()) {
            $paidRon = round(max(0.0, abs((float) $item->getAmountRon()) - $rest), 2);
        }

        $interest = $counts && $id !== null ? ($accessories?->interestByItemId[$id] ?? null) : null;
        $penaltyResult = $counts && $id !== null ? ($accessories?->penaltyByItemId[$id] ?? null) : null;
        $penalty = $penaltyResult !== null
            ? PenaltyBreakdownRow::fromResult($penaltyResult, $rest ?? 0.0, $case->contractualPenaltyCap(), $item->getDocumentNumber())
            : null;

        return new ClaimPositionRow(
            item: $item,
            state: $state,
            fxMissing: $item->needsManualFx() || $item->getAmountRon() === null,
            added: $id !== null && in_array($id, $addedItemIds, true),
            lastCounting: $counts && $countingCount === 1,
            restRon: $rest,
            paidRon: $paidRon,
            daysLate: $counts ? $this->daysLate($item->getDueDate(), $reference) : null,
            interest: $interest,
            penalty: $penalty,
            accessoryTotal: $interest?->total ?? $penaltyResult?->total,
            skipReasonKey: $counts && $id !== null ? ($accessories?->skipReasonByItemId[$id] ?? null) : null,
        );
    }

    private function daysLate(?\DateTimeImmutable $dueDate, \DateTimeImmutable $reference): ?int
    {
        if ($dueDate === null) {
            return null;
        }
        $diff = $dueDate->setTime(0, 0)->diff($reference);

        return $diff->invert === 1 ? 0 : (int) $diff->days;
    }

    /** Counting positions first, oldest due date first; the ones set aside after them in the same order. */
    private static function compare(ClaimPositionRow $a, ClaimPositionRow $b): int
    {
        return [$a->counts() ? 0 : 1, $a->item->getDueDate() === null ? 1 : 0, $a->item->getDueDate()?->format('Y-m-d') ?? '', $a->item->getId() ?? PHP_INT_MAX]
            <=> [$b->counts() ? 0 : 1, $b->item->getDueDate() === null ? 1 : 0, $b->item->getDueDate()?->format('Y-m-d') ?? '', $b->item->getId() ?? PHP_INT_MAX];
    }
}
