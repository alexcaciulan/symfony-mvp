<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Result of attempting to consume a subscription case slot when a case is
 * activated (`trimite_somatie`). Drives the caller's UI/redirect decision:
 * proceed silently, prompt overage payment, or block and onboard.
 */
enum SubscriptionSlotConsumptionOutcome: string
{
    /** Active subscription with a free slot: consumed from the plan, no charge. */
    case CONSUMED_FROM_PLAN = 'consumed_from_plan';

    /** Trial subscription with a free slot: consumed against the trial allowance. */
    case TRIAL_CONSUMED = 'trial_consumed';

    /** Active subscription, plan exhausted: a `case_extra` invoice was created. */
    case OVERAGE_INVOICE_CREATED = 'overage_invoice_created';

    /** Trial allowance exhausted: no invoice, user must subscribe to continue. */
    case TRIAL_EXHAUSTED = 'trial_exhausted';

    /** No usable (active/trial, unexpired) subscription at all. */
    case NO_ACTIVE_SUBSCRIPTION = 'no_active_subscription';

    public function label(): string
    {
        return 'enum.subscription_slot_consumption_outcome.' . $this->value;
    }
}
