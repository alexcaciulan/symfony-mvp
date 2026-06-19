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
 */
final class ContractualPenaltyCalculator
{
    public function calculate(
        float $amount,
        float $dailyRatePercent,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $referenceDate,
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

        return new PenaltyResult($penalty, [$period]);
    }
}
