<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Enum\RemovalStepOutcome;

/**
 * What removing one document from the wizard does to the steps already saved,
 * worked out before the lawyer confirms so the dialog can say it in advance.
 */
final readonly class DocumentRemovalPlan
{
    public function __construct(
        public RemovalStepOutcome $creditor,
        public ?string $creditorBefore,
        public ?string $creditorAfter,
        public RemovalStepOutcome $debtor,
        public ?string $debtorBefore,
        public ?string $debtorAfter,
        public bool $claimRefreshed,
        public int $documentsLeft,
        public ?Step3ClaimData $claimAfter = null,
        public ?float $principalBefore = null,
        public ?float $principalAfter = null,
    ) {}

    /** The principal to claim moves, which the dialog states in figures. */
    public function principalChanges(): bool
    {
        return $this->principalBefore !== null && $this->principalAfter !== null
            && abs($this->principalBefore - $this->principalAfter) >= 0.005;
    }

    /** Whether any saved step changes, which is when the dialog has more to say than a plain confirmation. */
    public function touchesSavedSteps(): bool
    {
        return $this->creditor !== RemovalStepOutcome::NOT_SAVED
            || $this->debtor !== RemovalStepOutcome::NOT_SAVED
            || $this->claimRefreshed;
    }

    public function resetsAParty(): bool
    {
        return $this->creditor === RemovalStepOutcome::REFILLED || $this->debtor === RemovalStepOutcome::REFILLED;
    }
}
