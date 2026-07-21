<?php

declare(strict_types=1);

namespace App\DTO\Billing;

use App\Entity\Invoice;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Enum\PlanChangeOutcome;

/**
 * Outcome of {@see \App\Service\Billing\SubscriptionService::changePlan()}.
 * Pure data carrier; the caller decides whether to send the user to checkout
 * (upgrade) or just confirm the scheduled change (downgrade).
 */
final readonly class PlanChangeResult
{
    public function __construct(
        public PlanChangeOutcome $outcome,
        public Subscription $subscription,
        /** The invoice to settle. Set only for UPGRADE_PENDING_PAYMENT. */
        public ?Invoice $invoice = null,
        /** The plan that takes over at the next renewal. Null for an upgrade. */
        public ?Plan $scheduledPlan = null,
    ) {}

    public static function upgradePendingPayment(Subscription $subscription, Invoice $invoice): self
    {
        return new self(PlanChangeOutcome::UPGRADE_PENDING_PAYMENT, $subscription, invoice: $invoice);
    }

    public static function downgradeScheduled(Subscription $subscription, Plan $scheduledPlan): self
    {
        return new self(PlanChangeOutcome::DOWNGRADE_SCHEDULED, $subscription, scheduledPlan: $scheduledPlan);
    }

    public static function changeScheduled(Subscription $subscription, Plan $scheduledPlan): self
    {
        return new self(PlanChangeOutcome::CHANGE_SCHEDULED, $subscription, scheduledPlan: $scheduledPlan);
    }
}
