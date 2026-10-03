<?php

declare(strict_types=1);

namespace App\DTO\Calculation;

/**
 * One position's contractual penalty laid out so the lawyer can redo it: the
 * days of delay, the daily rate, the computed figure and, when it binds, the
 * contractual ceiling claimed instead.
 */
final readonly class PenaltyBreakdownRow
{
    public function __construct(
        public ?string $number,
        public \DateTimeImmutable $firstDay,
        public \DateTimeImmutable $endDate,
        public float $base,
        public float $dailyRate,
        public int $days,
        public float $computed,
        public ?float $capPercent,
        public ?float $capAmount,
        public float $total,
    ) {}

    /** Null when the result holds no single accrual period to show. */
    public static function fromResult(PenaltyResult $result, float $base, ?float $capPercent, ?string $number = null): ?self
    {
        if (count($result->breakdown) !== 1) {
            return null;
        }
        $period = $result->breakdown[0];

        return new self(
            number: $number,
            // Days count from the due date exclusive, so the first day of delay is the next one.
            firstDay: $period->startDate->modify('+1 day'),
            endDate: $period->endDate,
            base: abs($base),
            dailyRate: $period->dailyRate,
            days: $period->days,
            computed: $period->periodPenalty,
            capPercent: $result->isCapped() ? $capPercent : null,
            capAmount: $result->capAmount,
            total: $result->total,
        );
    }
}
