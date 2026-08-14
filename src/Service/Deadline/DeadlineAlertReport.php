<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * Immutable result of {@see DeadlineAlertService::processAlerts()}: how many alerts
 * were emitted per tier. `sentLongRange` covers both extra tiers of the
 * limitation-type terms, which are counted together because their day counts differ
 * per deadline type and a per-number column would say nothing. Used for the
 * `app:check-deadlines` output.
 */
final readonly class DeadlineAlertReport
{
    public function __construct(
        public int $sent7 = 0,
        public int $sent3 = 0,
        public int $sent1 = 0,
        public int $expired = 0,
        public int $sentLongRange = 0,
    ) {}

    public function total(): int
    {
        return $this->sent7 + $this->sent3 + $this->sent1 + $this->expired + $this->sentLongRange;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'sent7' => $this->sent7,
            'sent3' => $this->sent3,
            'sent1' => $this->sent1,
            'expired' => $this->expired,
            'sentLongRange' => $this->sentLongRange,
            'total' => $this->total(),
        ];
    }
}
