<?php

declare(strict_types=1);

namespace App\DTO\Billing;

use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Enum\SubscriptionSlotConsumptionOutcome;

/**
 * Outcome of {@see \App\Service\Billing\SubscriptionService::consumeCaseSlot()}.
 * Pure data carrier; the caller decides UI/redirect based on {@see $outcome}
 * and {@see $allowsCaseActivation}.
 */
final readonly class SubscriptionSlotConsumption
{
    public function __construct(
        public SubscriptionSlotConsumptionOutcome $outcome,
        public ?Subscription $subscription = null,
        /** Set only for OVERAGE_INVOICE_CREATED. */
        public ?Invoice $invoice = null,
        /** False for TRIAL_EXHAUSTED and NO_ACTIVE_SUBSCRIPTION (case must not activate). */
        public bool $allowsCaseActivation = true,
    ) {}

    public static function consumedFromPlan(Subscription $subscription): self
    {
        return new self(SubscriptionSlotConsumptionOutcome::CONSUMED_FROM_PLAN, $subscription);
    }

    public static function trialConsumed(Subscription $subscription): self
    {
        return new self(SubscriptionSlotConsumptionOutcome::TRIAL_CONSUMED, $subscription);
    }

    public static function overage(Subscription $subscription, Invoice $invoice): self
    {
        return new self(SubscriptionSlotConsumptionOutcome::OVERAGE_INVOICE_CREATED, $subscription, $invoice);
    }

    public static function trialExhausted(Subscription $subscription): self
    {
        return new self(SubscriptionSlotConsumptionOutcome::TRIAL_EXHAUSTED, $subscription, allowsCaseActivation: false);
    }

    public static function noActiveSubscription(): self
    {
        return new self(SubscriptionSlotConsumptionOutcome::NO_ACTIVE_SUBSCRIPTION, allowsCaseActivation: false);
    }
}
