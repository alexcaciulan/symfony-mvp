<?php

declare(strict_types=1);

namespace App\Service\Calculation;

use App\DTO\Calculation\PenaltyPeriod;
use App\DTO\Calculation\PenaltyResult;

/**
 * Computes the contractual penalty (penalty clause, Civil Code art. 1538).
 *
 * Unlike statutory interest (OG 13/2011, an annual rate divided by 365), the
 * contractual penalty uses a fixed daily rate agreed by the parties:
 *
 *     penalty = principal * (dailyRate% / 100) * days
 *
 * The rate does not vary with the NBR reference rate, so the result is a single
 * continuous period. Rounding happens once, at display/persistence time, never
 * per day.
 *
 * A contract often caps the penalties at a share of the sum they accrue on
 * (for example, at most 10% of the invoiced work). The cap is part of the clause
 * the creditor relies on, so a figure above it is not one the creditor can ask
 * for: the total stops at the cap, and the result says it did.
 */
final class ContractualPenaltyCalculator
{
    public function calculate(
        float $amount,
        float $dailyRatePercent,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $referenceDate,
        ?float $capPercent = null,
    ): PenaltyResult {
        $start = $startDate->setTime(0, 0, 0);
        $reference = $referenceDate->setTime(0, 0, 0);

        if ($start >= $reference || $dailyRatePercent <= 0.0) {
            return new PenaltyResult(0.0, []);
        }

        $days = (int) $start->diff($reference)->days;
        $penalty = $amount * ($dailyRatePercent / 100.0) * $days;

        $period = new PenaltyPeriod(
            startDate: $start,
            endDate: $reference,
            dailyRate: $dailyRatePercent,
            days: $days,
            periodPenalty: $penalty,
        );

        if ($capPercent !== null && $capPercent > 0.0) {
            $cap = $amount * $capPercent / 100.0;
            if ($penalty > $cap) {
                return new PenaltyResult($cap, [$period], $cap);
            }
        }

        return new PenaltyResult($penalty, [$period]);
    }
}
