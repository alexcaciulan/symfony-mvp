<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\DTO\Billing\SubscriptionSlotConsumption;
use App\Entity\Invoice;
use App\Entity\LegalCase;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\NotificationType;
use App\Enum\SubscriptionSlotConsumptionOutcome;
use App\Enum\SubscriptionStatus;
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

    /** Paid billing-period length (DateTime modifier). */
    private const SUBSCRIPTION_PERIOD = '+1 month';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
        private readonly InvoicingService $invoicing,
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
     * if the user already has a paid subscription within its period (changing
     * plans, with proration, is a separate upgrade flow).
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
                newData: ['newPeriodEnd' => $subscription->getCurrentPeriodEnd()->format('Y-m-d')],
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
