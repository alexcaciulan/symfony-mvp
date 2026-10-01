<?php

declare(strict_types=1);

namespace App\DTO\StampDuty;

use App\Entity\City;
use App\Enum\StampDutyTargetStatus;
use App\Enum\UatType;

/**
 * Where the judicial stamp duty must be paid (OUG 80/2013 art. 40). Deliberately
 * carries no IBAN: the treasury account of each UAT is not published in a
 * consolidated official registry, and paying into the wrong UAT's account is
 * treated as non-payment, so the platform names the town hall and lets the
 * official portal resolve the account.
 *
 * In Bucharest the duty goes to the sector's budget and is collected by the sector's
 * local tax directorate, not by a town hall, so the card names that body instead.
 */
final readonly class StampDutyPaymentTarget
{
    public function __construct(
        public StampDutyTargetStatus $status,
        public ?City $uat = null,
    ) {}

    public function isResolved(): bool
    {
        return $this->status === StampDutyTargetStatus::RESOLVED && $this->uat !== null;
    }

    /** Translation key explaining the outcome, rendered on the stamp-duty card. */
    public function reasonKey(): string
    {
        return 'case_overview.stamp_duty.target.' . strtolower($this->status->value);
    }

    public function uatName(): ?string
    {
        return $this->uat?->getName();
    }

    /** Set only for a Bucharest sector, whose payee is the sector's tax directorate. */
    public function bucharestSectorNumber(): ?int
    {
        return $this->uat?->getType() === UatType::SECTOR ? UatType::bucharestSectorNumber($this->uat->getName()) : null;
    }

    public function countyName(): ?string
    {
        return $this->uat?->getCounty()->getName();
    }
}
