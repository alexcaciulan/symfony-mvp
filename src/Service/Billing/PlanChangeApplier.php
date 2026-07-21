<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Invoice;
use App\Enum\InvoiceType;
use App\Enum\SubscriptionStatus;
use App\Service\AuditLogService;

/**
 * Applies a paid plan change to its subscription.
 *
 * Anchored on {@see InvoicingService::markPaid()}, the single settlement point
 * for every path (gateway webhook, reconciliation cron, manual settlement), so
 * an upgrade takes effect exactly once no matter how the payment lands, including
 * a lost IPN recovered later.
 *
 * Deliberately depends on nothing but the audit log: SubscriptionService already
 * depends on InvoicingService, so this logic cannot live there without a
 * dependency cycle.
 */
class PlanChangeApplier
{
    public function __construct(
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * Moves the invoice's subscription onto the plan the invoice was raised for.
     * The billing period restarts from now at full price and the consumed-case
     * counter resets, because the user paid a whole new period rather than the
     * difference (no proration by product decision).
     *
     * No-op for anything that is not a settled plan change, so it is safe to call
     * unconditionally from the settlement path. Does not flush: the caller runs
     * inside a transaction and flushes once, keeping settlement atomic with the
     * plan switch.
     */
    public function applyPaidPlanChange(Invoice $invoice): void
    {
        if (InvoiceType::PLAN_CHANGE !== $invoice->getType()) {
            return;
        }

        $target = $invoice->getTargetPlan();
        $subscription = $invoice->getSubscription();
        if (null === $target || null === $subscription) {
            return;
        }

        $previousPlan = $subscription->getPlan();
        $now = new \DateTimeImmutable();

        $subscription
            ->setPlan($target)
            ->setStatus(SubscriptionStatus::ACTIVE)
            ->setCurrentPeriodStart($now)
            ->setCurrentPeriodEnd($now->modify(SubscriptionService::SUBSCRIPTION_PERIOD))
            ->setCasesConsumed(0)
            // A paid upgrade supersedes any downgrade scheduled for the next renewal.
            ->setPendingPlan(null)
            ->setPlanChangedAt($now);

        $this->auditLog->log(
            action: 'subscription_plan_changed',
            entityType: 'Subscription',
            entityId: (string) $subscription->getId(),
            oldData: ['plan' => $previousPlan->getName()],
            newData: [
                'plan' => $target->getName(),
                'invoiceId' => $invoice->getId(),
                'periodEnd' => $subscription->getCurrentPeriodEnd()->format('Y-m-d'),
            ],
            category: AuditLogService::CATEGORY_BILLING,
        );
    }
}
