<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\DTO\Billing\PlanChangeResult;
use App\DTO\Billing\SubscriptionSlotConsumption;
use App\Entity\Invoice;
use App\Entity\LegalCase;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Enum\NotificationType;
use App\Enum\SubscriptionSlotConsumptionOutcome;
use App\Enum\SubscriptionStatus;
use App\Repository\InvoiceRepository;
use App\Repository\PlanRepository;
use App\Repository\SubscriptionRepository;
use App\Service\AuditLogService;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Subscription lifecycle + the hybrid "included + overage" consumption logic.
 *
 * The paywall is placed at case activation (`trimite_somatie`), not at case
 * creation: drafts (AMIABIL) are free. The methods are self-contained; no
 * controller wires them into the case flow yet.
 */
// Not final: test double in SubscriptionRenewalServiceTest.
class SubscriptionService
{
    /** Trial length in days. Placeholder for go-live; confirm with product. */
    public const TRIAL_DAYS = 30;

    /** Paid billing-period length (DateTime modifier). Also used by PlanChangeApplier. */
    public const SUBSCRIPTION_PERIOD = '+1 month';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
        private readonly InvoicingService $invoicing,
        private readonly InvoiceRepository $invoices,
        private readonly AuditLogService $auditLog,
        private readonly NotificationDispatcherInterface $notifier,
        private readonly TranslatorInterface $translator,
    ) {}

    public function getCurrentSubscription(User $user): ?Subscription
    {
        return $this->subscriptions->findCurrentForUser($user);
    }

    /**
     * Consumes a case slot when a case is activated. Mutates + persists the
     * subscription (and, on overage, creates a CASE_EXTRA invoice) atomically.
     *
     * Idempotency relies on the single-fire nature of the `trimite_somatie`
     * transition (AMIABIL → SOMATIE_TRIMISA fires once per case).
     */
    public function consumeCaseSlot(LegalCase $case): SubscriptionSlotConsumption
    {
        $subscription = $this->getCurrentSubscription($case->getUser());
        if (null === $subscription) {
            return SubscriptionSlotConsumption::noActiveSubscription();
        }

        $plan = $subscription->getPlan();
        $hasFreeSlot = $subscription->getCasesConsumed() < $plan->getIncludedCases();
        $isTrial = SubscriptionStatus::TRIAL === $subscription->getStatus();

        if ($isTrial && !$hasFreeSlot) {
            return SubscriptionSlotConsumption::trialExhausted($subscription);
        }

        $result = $this->em->wrapInTransaction(function () use ($case, $subscription, $plan, $hasFreeSlot, $isTrial): SubscriptionSlotConsumption {
            $subscription->incrementCasesConsumed();

            if ($isTrial) {
                $result = SubscriptionSlotConsumption::trialConsumed($subscription);
            } elseif ($hasFreeSlot) {
                $result = SubscriptionSlotConsumption::consumedFromPlan($subscription);
            } else {
                // ACTIVE or CANCELED-within-period, plan exhausted: bill the overage.
                $result = SubscriptionSlotConsumption::overage($subscription, $this->invoicing->createCaseExtraInvoice($subscription, $case));
            }

            $this->auditLog->log(
                action: 'slot_consumed',
                entityType: 'Subscription',
                entityId: (string) $subscription->getId(),
                newData: [
                    'outcome' => $result->outcome->value,
                    'legalCaseId' => $case->getId(),
                    'casesConsumed' => $subscription->getCasesConsumed(),
                    'includedCases' => $plan->getIncludedCases(),
                    'invoiceId' => $result->invoice?->getId(),
                ],
                category: AuditLogService::CATEGORY_BILLING,
            );

            $this->em->flush();

            return $result;
        });

        // Notify the user of the extra charge POST-COMMIT: the overage slot and its
        // CASE_EXTRA invoice are already committed, so a notification-persist failure
        // (fault-isolated by the dispatcher, dedup-guarded on the invoice id) can
        // never roll the billed slot back. Same pattern as InvoicingService::markPaid.
        if (SubscriptionSlotConsumptionOutcome::OVERAGE_INVOICE_CREATED === $result->outcome && null !== $result->invoice) {
            $this->notifier->dispatch(new NotificationDispatch(
                user: $case->getUser(),
                legalCase: null,
                type: NotificationType::SLOT_OVERAGE,
                title: $this->translator->trans('notification.slot_overage.title'),
                message: $this->translator->trans('notification.slot_overage.message'),
                resourceLink: '/invoices',
                variant: 'warning',
                emailSubject: null,
                emailTemplate: null,
                dedupKey: sprintf('slot_overage:%d', $result->invoice->getId()),
            ));
        }

        return $result;
    }

    /**
     * Starts a trial subscription for a brand-new user on the configured trial
     * plan. Throws if no trial plan is seeded (misconfiguration).
     */
    public function startTrial(User $user): Subscription
    {
        $plan = $this->plans->findTrialPlan();
        if (null === $plan) {
            throw new \RuntimeException('No trial plan configured (Plan.isTrial = true).');
        }

        $now = new \DateTimeImmutable();
        $subscription = (new Subscription())
            ->setUser($user)
            ->setPlan($plan)
            ->setStatus(SubscriptionStatus::TRIAL)
            ->setCurrentPeriodStart($now)
            ->setCurrentPeriodEnd($now->modify('+' . self::TRIAL_DAYS . ' days'))
            ->setCasesConsumed(0);

        $this->em->persist($subscription);
        $this->em->flush();

        $this->auditLog->log(
            action: 'trial_started',
            entityType: 'Subscription',
            entityId: (string) $subscription->getId(),
            newData: ['plan' => $plan->getName(), 'trialDays' => self::TRIAL_DAYS],
            category: AuditLogService::CATEGORY_BILLING,
        );
        $this->em->flush();

        return $subscription;
    }

    /**
     * Subscribes a user to a paid plan: creates an ACTIVE monthly subscription
     * and its pending invoice. Converts an existing trial (cancels it). Refuses
     * if the user already has a paid subscription within its period: moving
     * between paid plans goes through {@see self::changePlan()} instead.
     *
     * @throws \DomainException when the user already has a paid subscription
     */
    public function subscribeToPlan(User $user, Plan $plan): Subscription
    {
        $current = $this->getCurrentSubscription($user);
        if (null !== $current && !$current->getPlan()->isTrial()) {
            throw new \DomainException('User already has a paid subscription.');
        }

        return $this->em->wrapInTransaction(function () use ($user, $plan, $current): Subscription {
            if (null !== $current) {
                // Convert from trial: end it now, the paid subscription takes over.
                $current->setStatus(SubscriptionStatus::CANCELED);
            }

            $now = new \DateTimeImmutable();
            $subscription = (new Subscription())
                ->setUser($user)
                ->setPlan($plan)
                ->setStatus(SubscriptionStatus::ACTIVE)
                ->setCurrentPeriodStart($now)
                ->setCurrentPeriodEnd($now->modify(self::SUBSCRIPTION_PERIOD))
                ->setCasesConsumed(0);

            $this->em->persist($subscription);
            $this->em->flush();

            $this->invoicing->createSubscriptionInvoice($subscription);

            $this->auditLog->log(
                action: 'subscription_created',
                entityType: 'Subscription',
                entityId: (string) $subscription->getId(),
                newData: ['plan' => $plan->getName(), 'convertedFromTrial' => null !== $current],
                category: AuditLogService::CATEGORY_BILLING,
            );
            $this->em->flush();

            return $subscription;
        });
    }

    /**
     * Requests a move to another paid plan.
     *
     * An upgrade (dearer plan) is charged at the new plan's full price and only
     * takes effect once that invoice is settled, applied by {@see PlanChangeApplier}
     * on the settlement path. Until then the subscription stays on its old plan,
     * so an abandoned checkout costs the user nothing.
     *
     * A downgrade (cheaper plan) is scheduled for the next renewal instead: applying
     * it now would owe a refund and could leave casesConsumed above the new plan's
     * included cases, turning already-activated cases into overage debt.
     *
     * Upgrade vs downgrade is decided on price, not includedCases: what matters here
     * is who owes money now, and Plan carries no explicit tier.
     *
     * @throws \DomainException when the subscription is a trial, when the target plan
     *                          is inactive or a trial, or when it is the current plan
     */
    public function changePlan(Subscription $subscription, Plan $newPlan): PlanChangeResult
    {
        if ($subscription->getPlan()->isTrial()) {
            throw new \DomainException('Trials convert through subscribeToPlan, not changePlan.');
        }
        if (!$newPlan->isActive() || $newPlan->isTrial()) {
            throw new \DomainException('Target plan is not a selectable paid plan.');
        }
        if ($newPlan->getId() === $subscription->getPlan()->getId()) {
            throw new \DomainException('Target plan is already the current plan.');
        }

        return $this->em->wrapInTransaction(function () use ($subscription, $newPlan): PlanChangeResult {
            // Supersede an unpaid earlier request, so a late payment on it cannot
            // apply a plan the user has since moved away from.
            foreach ($this->invoices->findPendingByType($subscription, InvoiceType::PLAN_CHANGE, forUpdate: true) as $stale) {
                $stale->setStatus(InvoiceStatus::CANCELED);
            }

            $comparison = $newPlan->comparePriceTo($subscription->getPlan());

            if ($comparison <= 0) {
                $subscription->setPendingPlan($newPlan);

                $this->auditLog->log(
                    action: 'subscription_plan_change_scheduled',
                    entityType: 'Subscription',
                    entityId: (string) $subscription->getId(),
                    oldData: ['plan' => $subscription->getPlan()->getName()],
                    newData: [
                        'pendingPlan' => $newPlan->getName(),
                        'effectiveAt' => $subscription->getCurrentPeriodEnd()->format('Y-m-d'),
                    ],
                    category: AuditLogService::CATEGORY_BILLING,
                );
                $this->em->flush();

                return $comparison < 0
                    ? PlanChangeResult::downgradeScheduled($subscription, $newPlan)
                    : PlanChangeResult::changeScheduled($subscription, $newPlan);
            }

            $invoice = $this->invoicing->createPlanChangeInvoice($subscription, $newPlan);

            $this->auditLog->log(
                action: 'subscription_plan_change_requested',
                entityType: 'Subscription',
                entityId: (string) $subscription->getId(),
                oldData: ['plan' => $subscription->getPlan()->getName()],
                newData: ['targetPlan' => $newPlan->getName(), 'invoiceId' => $invoice->getId()],
                category: AuditLogService::CATEGORY_BILLING,
            );
            $this->em->flush();

            return PlanChangeResult::upgradePendingPayment($subscription, $invoice);
        });
    }

    /**
     * Releases subscriptions whose checkout was abandoned, along with their
     * still-open invoices.
     *
     * Without this, an abandoned checkout locks the account forever: the row stays
     * ACTIVE and within its period, so it counts as the current subscription and
     * every later plan choice is refused. PAST_DUE is not usable and is excluded
     * from findCurrentForUser, which both gates access and frees the user to
     * subscribe again.
     *
     * @return int number of subscriptions released
     */
    public function expireAbandonedCheckouts(\DateTimeImmutable $before): int
    {
        $stale = $this->subscriptions->findAbandonedCheckoutsCreatedBefore($before);
        if ([] === $stale) {
            return 0;
        }

        return $this->em->wrapInTransaction(function () use ($stale): int {
            foreach ($stale as $subscription) {
                $subscription->setStatus(SubscriptionStatus::PAST_DUE);

                foreach ([InvoiceType::SUBSCRIPTION, InvoiceType::PLAN_CHANGE] as $type) {
                    foreach ($this->invoices->findPendingByType($subscription, $type, forUpdate: true) as $invoice) {
                        $invoice->setStatus(InvoiceStatus::CANCELED);
                    }
                }

                $this->auditLog->log(
                    action: 'subscription_expired_unpaid',
                    entityType: 'Subscription',
                    entityId: (string) $subscription->getId(),
                    newData: ['plan' => $subscription->getPlan()->getName()],
                    category: AuditLogService::CATEGORY_BILLING,
                );
            }

            $this->em->flush();

            return \count($stale);
        });
    }

    /**
     * Drops a downgrade scheduled for the next renewal, leaving the current plan
     * in place. No-op when nothing is scheduled.
     */
    public function cancelScheduledPlanChange(Subscription $subscription): void
    {
        $pending = $subscription->getPendingPlan();
        if (null === $pending) {
            return;
        }

        $subscription->setPendingPlan(null);

        $this->auditLog->log(
            action: 'subscription_plan_change_canceled',
            entityType: 'Subscription',
            entityId: (string) $subscription->getId(),
            oldData: ['pendingPlan' => $pending->getName()],
            category: AuditLogService::CATEGORY_BILLING,
        );

        $this->em->flush();
    }

    /**
     * Voluntary cancellation. The subscription stays usable until
     * currentPeriodEnd (enforced by the date guard in findCurrentForUser);
     * it simply will not renew.
     */
    public function cancelSubscription(Subscription $subscription): void
    {
        $subscription->setStatus(SubscriptionStatus::CANCELED);

        // GDPR minimization: a saved card instrument has no legitimate purpose once
        // the subscription will not renew, so drop the recurring token immediately.
        $subscription->setRecurringToken(null)
            ->setRecurringTokenExpiresAt(null)
            ->setCardMask(null);

        $this->auditLog->log(
            action: 'subscription_canceled',
            entityType: 'Subscription',
            entityId: (string) $subscription->getId(),
            category: AuditLogService::CATEGORY_BILLING,
        );

        $this->em->flush();
    }

    /**
     * Rolls the subscription into a new monthly period: issues the subscription
     * invoice, extends the period and resets the consumed-case counter, all in
     * one transaction. Returns the pending renewal invoice so the caller can
     * charge it. Wired by {@see SubscriptionRenewalService} (renewal cron).
     *
     * The period is rolled optimistically; if the subsequent charge fails, the
     * caller flips the status to PAST_DUE, which gates access regardless of the
     * (now rolled) period date. So a rolled-but-unpaid period never leaks access.
     */
    public function renewSubscription(Subscription $subscription): Invoice
    {
        return $this->em->wrapInTransaction(function () use ($subscription): Invoice {
            // An unpaid upgrade offer dies with the period it was raised in. Left
            // open it would be settled later against a period this renewal has
            // already rolled and billed, charging the user twice and letting the
            // applier overwrite the fresh period and case counter.
            $supersededPlanChanges = 0;
            foreach ($this->invoices->findPendingByType($subscription, InvoiceType::PLAN_CHANGE, forUpdate: true) as $stale) {
                $stale->setStatus(InvoiceStatus::CANCELED);
                ++$supersededPlanChanges;
            }

            // A scheduled downgrade takes over here, before the invoice is raised,
            // so the renewal is billed at the new plan's price.
            $pendingPlan = $subscription->getPendingPlan();
            if (null !== $pendingPlan) {
                $subscription
                    ->setPlan($pendingPlan)
                    ->setPendingPlan(null)
                    ->setPlanChangedAt(new \DateTimeImmutable());
            }

            $invoice = $this->invoicing->createSubscriptionInvoice($subscription);

            $newStart = $subscription->getCurrentPeriodEnd();
            $subscription
                ->setStatus(SubscriptionStatus::ACTIVE)
                ->setCurrentPeriodStart($newStart)
                ->setCurrentPeriodEnd($newStart->modify(self::SUBSCRIPTION_PERIOD))
                ->setCasesConsumed(0);

            $this->auditLog->log(
                action: 'subscription_renewed',
                entityType: 'Subscription',
                entityId: (string) $subscription->getId(),
                newData: [
                    'newPeriodEnd' => $subscription->getCurrentPeriodEnd()->format('Y-m-d'),
                    'plan' => $subscription->getPlan()->getName(),
                    'appliedScheduledPlan' => null !== $pendingPlan,
                    'supersededPlanChanges' => $supersededPlanChanges,
                ],
                category: AuditLogService::CATEGORY_BILLING,
            );

            $this->em->flush();

            return $invoice;
        });
    }

    /**
     * Marks a subscription PAST_DUE after a failed recurring charge or an expired
     * token. PAST_DUE is not usable ({@see SubscriptionStatus::isUsable()}), so
     * access is gated immediately regardless of the billing-period date.
     */
    public function markPastDue(Subscription $subscription, string $reason): void
    {
        $subscription->setStatus(SubscriptionStatus::PAST_DUE);

        $this->auditLog->log(
            action: 'subscription_past_due',
            entityType: 'Subscription',
            entityId: (string) $subscription->getId(),
            newData: ['reason' => $reason],
            category: AuditLogService::CATEGORY_BILLING,
        );

        $this->em->flush();
    }

    /**
     * The cheapest active paid plan that includes more cases than the current
     * one, for the "upgrade is cheaper than overage" nudge. Read-only: it only
     * suggests a plan, it does not change the subscription.
     */
    public function recommendUpgrade(Subscription $subscription): ?Plan
    {
        $currentIncluded = $subscription->getPlan()->getIncludedCases();

        foreach ($this->plans->findActive() as $plan) {
            if (!$plan->isTrial() && $plan->getIncludedCases() > $currentIncluded) {
                return $plan;
            }
        }

        return null;
    }
}
