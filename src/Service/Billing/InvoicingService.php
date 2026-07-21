<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Invoice;
use App\Entity\LegalCase;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Enum\NotificationType;
use App\Message\IssueFiscalInvoiceMessage;
use App\Repository\InvoiceRepository;
use App\Service\AuditLogService;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates and settles invoices. Invoices are internal billing records, NOT
 * fiscally valid Romanian invoices (no series/number/VAT) — the real fiscal
 * layer (e-Factura) is post-MVP.
 */
// Not final: test doubles in ProcessPaymentWebhookMessageHandlerTest / PaymentReconciliationServiceTest.
class InvoicingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceRepository $invoices,
        private readonly AuditLogService $auditLog,
        private readonly MessageBusInterface $bus,
        private readonly NotificationDispatcherInterface $notifier,
        private readonly TranslatorInterface $translator,
        private readonly PlanChangeApplier $planChangeApplier,
    ) {}

    /** Recurring subscription charge for the plan's monthly price. */
    public function createSubscriptionInvoice(Subscription $subscription): Invoice
    {
        $invoice = (new Invoice())
            ->setUser($subscription->getUser())
            ->setSubscription($subscription)
            ->setType(InvoiceType::SUBSCRIPTION)
            ->setStatus(InvoiceStatus::PENDING)
            ->setAmount($subscription->getPlan()->getPriceMonthly());

        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    /**
     * Full price of the plan a subscription is moving to. The change itself is
     * deferred to settlement: {@see PlanChangeApplier} reads `targetPlan` off this
     * invoice when it is paid, so an abandoned checkout leaves the subscription
     * untouched on its old plan.
     */
    public function createPlanChangeInvoice(Subscription $subscription, Plan $targetPlan): Invoice
    {
        $invoice = (new Invoice())
            ->setUser($subscription->getUser())
            ->setSubscription($subscription)
            ->setTargetPlan($targetPlan)
            ->setType(InvoiceType::PLAN_CHANGE)
            ->setStatus(InvoiceStatus::PENDING)
            ->setAmount($targetPlan->getPriceMonthly());

        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    /** Per-case charge billed when the plan's included cases are exhausted. */
    public function createCaseExtraInvoice(Subscription $subscription, LegalCase $legalCase): Invoice
    {
        $invoice = (new Invoice())
            ->setUser($subscription->getUser())
            ->setSubscription($subscription)
            ->setLegalCase($legalCase)
            ->setType(InvoiceType::CASE_EXTRA)
            ->setStatus(InvoiceStatus::PENDING)
            ->setAmount($subscription->getPlan()->getPricePerExtra());

        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    /**
     * Stores the gateway's transaction reference (ntpID) on a still-open invoice
     * at checkout start, so a later reconciliation can re-query its status if the
     * IPN is lost. Does not settle the invoice.
     */
    public function setExternalReference(Invoice $invoice, string $externalRef): void
    {
        $invoice->setExternalId($externalRef);
        $this->em->flush();
    }

    /**
     * Settles an invoice: transitions PENDING → PAID exactly once, then issues
     * the fiscal invoice asynchronously. This is the single source of truth for
     * the guard, made atomic with a pessimistic write lock so concurrent callers
     * (webhook worker + reconciliation cron, or Messenger retries) cannot both
     * settle the same invoice and dispatch two fiscal invoices. Same pattern as
     * FiscalNumberingService.
     *
     * The flush inside the transaction also persists any other managed changes
     * staged by the caller in the same unit of work (e.g. the recurring token on
     * the subscription set by the webhook handler).
     *
     * `$source` records how the settlement happened (webhook/reconciliation/manual)
     * for the audit trail.
     */
    public function markPaid(Invoice $invoice, ?string $externalRef = null, string $source = 'manual'): void
    {
        $settled = $this->em->wrapInTransaction(function () use ($invoice, $externalRef, $source): bool {
            $locked = $this->em->find(Invoice::class, $invoice->getId(), LockMode::PESSIMISTIC_WRITE);
            if (null === $locked || InvoiceStatus::PENDING !== $locked->getStatus()) {
                // Already settled by a concurrent process: no-op, no double dispatch.
                return false;
            }

            $locked->markPaid();
            if (null !== $externalRef) {
                $locked->setExternalId($externalRef);
            }

            // Inside the transaction so settlement and the plan switch commit
            // together: no window where the invoice reads PAID but the
            // subscription still sits on the old plan. No-op for other types.
            $this->planChangeApplier->applyPaidPlanChange($locked);

            $this->auditLog->log(
                action: 'invoice_paid',
                entityType: 'Invoice',
                entityId: (string) $locked->getId(),
                newData: [
                    'type' => $locked->getType()->value,
                    'amount' => $locked->getAmount(),
                    'externalRef' => $externalRef,
                    'source' => $source,
                ],
                category: AuditLogService::CATEGORY_BILLING,
            );

            $this->em->flush();

            return true;
        });

        // Issue the fiscal invoice asynchronously ONLY on the settling call. The
        // transaction has committed the PAID state, so the worker reads it fresh.
        // Runs post-commit (never inside the transaction) to keep settlement atomic:
        // the notification fan-out and the async dispatch are side effects that must
        // not roll the PAID state back if a channel misbehaves.
        if ($settled && null !== $invoice->getId()) {
            $this->bus->dispatch(new IssueFiscalInvoiceMessage($invoice->getId()));
            $this->notifyPaymentSucceeded($invoice);
        }
    }

    /**
     * Confirms to the client that the subscription charge went through. In-app only
     * (no dedicated email template yet); the dispatcher is fault-isolated and the
     * dedup key makes a webhook/reconciliation double-settle a no-op.
     */
    private function notifyPaymentSucceeded(Invoice $invoice): void
    {
        $this->notifier->dispatch(new NotificationDispatch(
            user: $invoice->getUser(),
            legalCase: null,
            type: NotificationType::PAYMENT_SUCCEEDED,
            title: $this->translator->trans('notification.payment_succeeded.title'),
            message: $this->translator->trans('notification.payment_succeeded.message'),
            resourceLink: '/subscription',
            variant: 'success',
            emailSubject: null,
            emailTemplate: null,
            dedupKey: sprintf('payment_succeeded:%d', $invoice->getId()),
        ));
    }

    /**
     * @param array{status?: InvoiceStatus, type?: InvoiceType} $filters
     *
     * @return Invoice[]
     */
    public function getInvoicesByUser(User $user, array $filters = []): array
    {
        $invoices = $this->invoices->findByUser($user);

        if (isset($filters['status'])) {
            $invoices = array_filter($invoices, static fn (Invoice $i) => $i->getStatus() === $filters['status']);
        }
        if (isset($filters['type'])) {
            $invoices = array_filter($invoices, static fn (Invoice $i) => $i->getType() === $filters['type']);
        }

        return array_values($invoices);
    }
}
