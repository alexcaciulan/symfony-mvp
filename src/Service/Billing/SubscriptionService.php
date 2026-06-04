<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\DTO\Billing\SubscriptionSlotConsumption;
use App\Entity\LegalCase;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use App\Repository\PlanRepository;
use App\Repository\SubscriptionRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Subscription lifecycle + the hybrid "included + overage" consumption logic.
 *
 * The paywall is placed at case activation (`trimite_somatie`), not at case
 * creation: drafts (AMIABIL) are free. The methods are self-contained; no
 * controller wires them into the case flow yet.
 */
final class SubscriptionService
{
    /** Trial length in days. Placeholder for go-live; confirm with product. */
    public const TRIAL_DAYS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PlanRepository $plans,
        private readonly InvoicingService $invoicing,
        private readonly AuditLogService $auditLog,
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

        return $this->em->wrapInTransaction(function () use ($case, $subscription, $plan, $hasFreeSlot, $isTrial): SubscriptionSlotConsumption {
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
     * Voluntary cancellation. The subscription stays usable until
     * currentPeriodEnd (enforced by the date guard in findCurrentForUser);
     * it simply will not renew.
     */
    public function cancelSubscription(Subscription $subscription): void
    {
        $subscription->setStatus(SubscriptionStatus::CANCELED);

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
     * one transaction. Not yet wired (a renewal cron will invoke it).
     */
    public function renewSubscription(Subscription $subscription): void
    {
        $this->em->wrapInTransaction(function () use ($subscription): void {
            $this->invoicing->createSubscriptionInvoice($subscription);

            $newStart = $subscription->getCurrentPeriodEnd();
            $subscription
                ->setStatus(SubscriptionStatus::ACTIVE)
                ->setCurrentPeriodStart($newStart)
                ->setCurrentPeriodEnd($newStart->modify('+1 month'))
                ->setCasesConsumed(0);

            $this->auditLog->log(
                action: 'subscription_renewed',
                entityType: 'Subscription',
                entityId: (string) $subscription->getId(),
                newData: ['newPeriodEnd' => $subscription->getCurrentPeriodEnd()->format('Y-m-d')],
                category: AuditLogService::CATEGORY_BILLING,
            );

            $this->em->flush();
        });
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
