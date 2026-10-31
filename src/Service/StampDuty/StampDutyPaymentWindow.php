<?php

declare(strict_types=1);

namespace App\Service\StampDuty;

use App\Entity\LegalCase;

/**
 * The days the lawyer has to pay the stamp duty once the court file number is known.
 *
 * Not a legal term. The law gives the court a notice to stamp with at most ten days
 * (OUG 80/2013 art. 33 para. 2, CPC art. 200), but that notice is served wherever the
 * court chooses, often at the client's seat, and the application never sees it. The
 * window here is the rule of practice the consulting lawyer set instead: pay through
 * the electronic registry within five days of the number being allocated, so the
 * notice, if it ever comes, finds the duty paid. While the window is open the daily
 * alerting job repeats itself every day; after it the reminder falls back to once a
 * week, because by then the case is either stamped, or the court has asked and a real
 * term is running on it.
 */
final class StampDutyPaymentWindow
{
    public const DAYS = 5;

    /** The last day of the window, null while no number points at the case. */
    public function payBy(LegalCase $case): ?\DateTimeImmutable
    {
        $start = $case->stampDutyPaymentClockStart();
        if ($start === null) {
            return null;
        }

        return $start->setTime(0, 0)->modify(sprintf('+%d days', self::DAYS));
    }

    /**
     * Whether a run on this day falls inside the window, the last day included. A case
     * whose clock never started is treated as outside it: there is no date to count
     * from, so the reminder keeps its ordinary weekly pace.
     */
    public function isOpenOn(LegalCase $case, \DateTimeImmutable $day): bool
    {
        $payBy = $this->payBy($case);

        return $payBy !== null && $day->setTime(0, 0) <= $payBy;
    }
}
