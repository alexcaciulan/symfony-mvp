<?php

declare(strict_types=1);

namespace App\Service\Claim;

use App\Entity\ClaimItem;

/**
 * Groups claim positions by their CPC art. 99 cause.
 *
 * Single source of truth for how one set of invoices is valued, shared by the
 * competent-court routing (CompetentCourtResolver) and by the note that
 * justifies a cumulative object value on the petition
 * (PaymentOrderRequestGeneratorService). One implementation stops the two from
 * diverging on partial extractions where some invoices carry no stated cause:
 * the court and the justification on the document filed at it must read the
 * positions the same way.
 */
final class ClaimCauseGrouper
{
    /**
     * Principal per cause, plus whether the grouping itself is uncertain.
     *
     * Uncertain means the file names several causes AND carries positions that
     * name none, so those cannot be attributed to any of them. Positions with an
     * unresolved RON value are skipped. Positions carrying no stated cause join
     * the file's single stated cause when there is exactly one, and form a cause
     * of their own when there is none.
     *
     * @param  iterable<ClaimItem> $items
     * @return array{0: array<string, float>, 1: bool}
     */
    public function group(iterable $items): array
    {
        $labelled = [];
        $unlabelled = 0.0;
        $hasUnlabelled = false;

        foreach ($items as $item) {
            $amount = $item->signedAmountRon();
            if ($amount === null) {
                continue;
            }

            $cause = $item->causeKey();
            if ($cause === '') {
                $hasUnlabelled = true;
                $unlabelled += $amount;

                continue;
            }

            $labelled[$cause] = ($labelled[$cause] ?? 0.0) + $amount;
        }

        if (!$hasUnlabelled) {
            return [$labelled, false];
        }

        if ($labelled === []) {
            return [['' => $unlabelled], false];
        }

        if (count($labelled) === 1) {
            $only = array_key_first($labelled);
            $labelled[$only] += $unlabelled;

            return [$labelled, false];
        }

        $labelled[''] = $unlabelled;

        return [$labelled, true];
    }

    /**
     * Whether every counting position resolves to a single cause, so the object
     * of the claim is one head worth the sum of all positions (CPC art. 99 alin.
     * 2). False as soon as two distinct stated causes coexist, matching the
     * court resolver: an unlabelled invoice joins the one contract every other
     * invoice names rather than counting as a separate cause.
     *
     * @param iterable<ClaimItem> $items
     */
    public function isSingleCause(iterable $items): bool
    {
        [$byCause, $uncertain] = $this->group($items);

        return !$uncertain && count($byCause) === 1;
    }
}
