<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * Immutable result of {@see BlockedCaseAlertService::process()}: how many alerts were
 * raised per reason. These are events dispatched, not messages delivered: the dedup key
 * decides delivery downstream, so a run can report work while sending nothing, which is
 * the intended steady state on a case that stays blocked.
 */
final readonly class BlockedCaseAlertReport
{
    public function __construct(
        public int $stampDutyDue = 0,
        public int $stampDutyDueUnconfirmed = 0,
        public int $enforcementRegistrationNumberMissing = 0,
    ) {}

    public function total(): int
    {
        return $this->stampDutyDue + $this->stampDutyDueUnconfirmed + $this->enforcementRegistrationNumberMissing;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'stampDutyDue' => $this->stampDutyDue,
            'stampDutyDueUnconfirmed' => $this->stampDutyDueUnconfirmed,
            'enforcementRegistrationNumberMissing' => $this->enforcementRegistrationNumberMissing,
            'total' => $this->total(),
        ];
    }
}
