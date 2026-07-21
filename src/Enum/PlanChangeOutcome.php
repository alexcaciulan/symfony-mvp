<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Result of requesting a move to another paid plan. Drives the caller's redirect:
 * send to checkout, confirm the scheduled change, or report that nothing changed.
 */
enum PlanChangeOutcome: string
{
    /**
     * More expensive plan: a full-price invoice was created and the subscription
     * stays on the old plan until that invoice is settled.
     */
    case UPGRADE_PENDING_PAYMENT = 'upgrade_pending_payment';

    /** Cheaper plan: scheduled on the subscription, applied at the next renewal. */
    case DOWNGRADE_SCHEDULED = 'downgrade_scheduled';

    /** Same price as the current plan: scheduled like a downgrade, no invoice. */
    case CHANGE_SCHEDULED = 'change_scheduled';

    public function label(): string
    {
        return 'enum.plan_change_outcome.' . $this->value;
    }
}
