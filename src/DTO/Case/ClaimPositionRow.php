<?php

declare(strict_types=1);

namespace App\DTO\Case;

use App\DTO\Calculation\InterestResult;
use App\DTO\Calculation\PenaltyBreakdownRow;
use App\Entity\ClaimItem;

/**
 * One claim position as the claim card shows it: its state, its RON figures and
 * the accessory computed on it, taken from the case aggregate and never
 * recomputed here.
 */
final readonly class ClaimPositionRow
{
    public const STATE_COUNTING = 'counting';
    public const STATE_EXCLUDED = 'excluded';
    public const STATE_PENDING = 'pending';

    public function __construct(
        public ClaimItem $item,
        public string $state,
        public bool $fxMissing,
        public bool $added,
        public bool $lastCounting,
        public ?float $restRon,
        public float $paidRon,
        public ?int $daysLate,
        public ?InterestResult $interest,
        public ?PenaltyBreakdownRow $penalty,
        public ?float $accessoryTotal,
        public ?string $skipReasonKey,
    ) {}

    public function counts(): bool
    {
        return $this->state === self::STATE_COUNTING;
    }

    public function isExcluded(): bool
    {
        return $this->state === self::STATE_EXCLUDED;
    }

    /** Which breakdown the row can open: 'interest', 'penalty' or null when there are no periods to show. */
    public function breakdownKind(): ?string
    {
        if (!$this->counts()) {
            return null;
        }
        if ($this->penalty !== null) {
            return 'penalty';
        }
        if ($this->interest !== null && $this->interest->breakdown !== []) {
            return 'interest';
        }

        return null;
    }

    /** Value of the client-side filter: 'in', 'excluded' or 'attention'. */
    public function filterState(): string
    {
        return match ($this->state) {
            self::STATE_COUNTING => 'in',
            self::STATE_EXCLUDED => 'excluded',
            default => 'attention',
        };
    }
}
