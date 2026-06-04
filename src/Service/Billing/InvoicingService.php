<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Invoice;
use App\Entity\LegalCase;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Repository\InvoiceRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates and settles invoices. Invoices are internal billing records, NOT
 * fiscally valid Romanian invoices (no series/number/VAT) — the real fiscal
 * layer (e-Factura) is post-MVP.
 */
final class InvoicingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceRepository $invoices,
        private readonly AuditLogService $auditLog,
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

    public function markPaid(Invoice $invoice, ?string $externalRef = null): void
    {
        $invoice->markPaid();
        if (null !== $externalRef) {
            $invoice->setExternalId($externalRef);
        }

        $this->auditLog->log(
            action: 'invoice_paid',
            entityType: 'Invoice',
            entityId: (string) $invoice->getId(),
            newData: [
                'type' => $invoice->getType()->value,
                'amount' => $invoice->getAmount(),
                'externalRef' => $externalRef,
            ],
            category: AuditLogService::CATEGORY_BILLING,
        );

        $this->em->flush();
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
