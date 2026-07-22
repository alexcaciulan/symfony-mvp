<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\DTO\Calculation\ClaimTotals;
use App\Entity\ClaimItem;
use App\Entity\LegalCase;

/**
 * Single owner of the denormalized claim scalars on {@see LegalCase}.
 *
 * Everything else reads `LegalCase::$amount`, `$dueDate`, `$invoiceNumber` and
 * `$invoiceDate`; only this service writes them, and only from the positions.
 */
final class ClaimTotalsService
{
    /**
     * Totals over the positions that count, without touching the case.
     *
     * @param iterable<ClaimItem> $items
     */
    public function totals(iterable $items): ClaimTotals
    {
        $principal = 0.0;
        $counted = [];
        $excluded = [];
        $needsFx = [];
        $unimputedIds = [];
        $unimputedTotal = 0.0;
        $count = 0;
        $countedCount = 0;
        $needsFxCount = 0;
        $earliest = null;

        foreach ($items as $item) {
            ++$count;
            $id = $item->getId();

            if ($item->hasUnimputedPayment()) {
                $unimputedTotal += (float) $item->getPaidAmount();
                if ($id !== null) {
                    $unimputedIds[] = $id;
                }
            }

            if ($item->needsManualFx() || $item->getAmountRon() === null) {
                ++$needsFxCount;
                if ($id !== null) {
                    $needsFx[] = $id;
                }

                continue;
            }

            if (!$item->isConfirmedByLawyer() || $item->isExcludedByLawyer()) {
                if ($id !== null) {
                    $excluded[] = $id;
                }

                continue;
            }

            // Payments are recorded, never imputed here: see ClaimTotals.
            // A credit note carries a negative value and subtracts.
            $principal += $item->signedAmountRon() ?? 0.0;
            ++$countedCount;
            if ($id !== null) {
                $counted[] = $id;
            }

            if ($item->isCreditNote()) {
                continue;
            }

            $dueDate = $item->getDueDate();
            $earliestDue = $earliest?->getDueDate();
            if ($dueDate !== null && ($earliestDue === null || $dueDate < $earliestDue)) {
                $earliest = $item;
            }
        }

        return new ClaimTotals(
            principalRon: round($principal, 2),
            earliestDueDate: $earliest?->getDueDate(),
            earliestInvoiceNumber: $earliest?->getDocumentNumber(),
            earliestInvoiceDate: $earliest?->getDocumentDate(),
            itemCount: $count,
            countedItemIds: $counted,
            excludedItemIds: $excluded,
            needsManualFxItemIds: $needsFx,
            unimputedPaidTotal: round($unimputedTotal, 2),
            unimputedPaymentItemIds: $unimputedIds,
            countedCount: $countedCount,
            needsManualFxCount: $needsFxCount,
        );
    }

    /**
     * Writes the totals onto the case.
     *
     * Two cases are left exactly as they are, and they are not the same thing:
     * a case carrying no positions at all (created before positions existed),
     * and a case whose every position awaits a manual exchange rate, where the
     * stored scalars are the only figures anyone has. A case whose positions
     * were all deliberately excluded is written, zero included, because leaving
     * a claimable principal standing behind an emptied claim is what makes the
     * document generator bill interest on a sum nobody kept.
     *
     * A scalar is only ever overwritten by a value the positions actually
     * supply. The due date and the invoice identity the lawyer entered are not
     * erased by positions that carry none: a null due date silently disables
     * the exigibility check of CPC art. 1013.
     */
    public function recalculate(LegalCase $case): ClaimTotals
    {
        $totals = $this->totals($case->getClaimItems());

        if ($totals->itemCount === 0) {
            return $totals;
        }

        if ($totals->countedCount === 0 && $totals->needsManualFxCount > 0) {
            return $totals;
        }

        $case->setAmount(sprintf('%.2f', $totals->principalRon));
        // The positions are totalled in RON, so that is the currency of the
        // figure just written; the original per-position values stay on them.
        $case->setCurrency('RON');

        if ($totals->earliestDueDate !== null) {
            $case->setDueDate(\DateTime::createFromImmutable($totals->earliestDueDate));
        }
        if ($totals->earliestInvoiceNumber !== null) {
            $case->setInvoiceNumber($totals->earliestInvoiceNumber);
        }
        if ($totals->earliestInvoiceDate !== null) {
            $case->setInvoiceDate(\DateTime::createFromImmutable($totals->earliestInvoiceDate));
        }

        return $totals;
    }
}
