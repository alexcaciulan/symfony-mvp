<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\FiscalInvoice;
use App\Entity\FiscalInvoiceLine;
use App\Entity\Invoice;
use App\Enum\FiscalInvoiceKind;
use App\Enum\FiscalInvoiceStatus;
use App\Repository\FiscalInvoiceRepository;
use App\Service\AuditLogService;
use App\Service\Billing\EInvoicing\EInvoicingException;
use App\Service\Billing\EInvoicing\EInvoicingProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues a fiscal invoice for a paid {@see Invoice} through the active provider
 * (the app is a thin mirror: the provider owns numbering/VAT/PDF/SPV).
 *
 * Idempotent: if a {@see FiscalInvoice} already carries a providerInvoiceId for
 * this Invoice, it is returned untouched, so a retry never issues a duplicate at
 * the provider. A leftover DRAFT (no providerInvoiceId) is reused and completed.
 *
 * Not final: the message handler mocks it in tests.
 */
class FiscalInvoiceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FiscalInvoiceRepository $fiscalInvoices,
        private readonly FiscalInvoiceFactory $factory,
        private readonly EInvoicingProviderInterface $provider,
        private readonly AuditLogService $auditLog,
    ) {}

    public function issueForPaidInvoice(Invoice $invoice): FiscalInvoice
    {
        $existing = $this->fiscalInvoices->findOneByInvoice($invoice);
        if (null !== $existing && null !== $existing->getProviderInvoiceId()) {
            return $existing;
        }

        $fiscal = $existing ?? $this->factory->buildDraft($invoice);

        // Persist the DRAFT before calling the provider so a failed issuance
        // leaves a reconcilable row (the reconcile command retries it) instead
        // of vanishing.
        if (null === $fiscal->getId()) {
            $this->em->persist($fiscal);
            $this->em->flush();
        }

        $request = $this->factory->buildIssueRequest($fiscal);

        $result = $this->provider->issue($request);

        $fiscal->setProviderName($result->providerName)
            ->setSeries($result->series)
            ->setNumber($result->number)
            ->setProviderInvoiceId($result->providerInvoiceId)
            ->setEInvoiceStatus($result->eInvoiceStatus)
            ->setSpvId($result->spvId)
            ->setPdfUrl($result->pdfUrl)
            ->setStatus(FiscalInvoiceStatus::ISSUED);

        // The mirror is already managed (persisted as DRAFT above, or loaded
        // from the DB), so a flush is enough to store the issuance result.
        $this->em->flush();

        $this->auditLog->log(
            action: 'fiscal_invoice_issued',
            entityType: 'FiscalInvoice',
            entityId: (string) $fiscal->getId(),
            newData: [
                'provider' => $result->providerName,
                'series' => $result->series,
                'number' => $result->number,
                'grossTotal' => $fiscal->getGrossTotal(),
                'eInvoiceStatus' => $result->eInvoiceStatus->value,
            ],
            category: AuditLogService::CATEGORY_BILLING,
        );

        return $fiscal;
    }

    /** Poll the provider for the SPV status and store it on the mirror. */
    public function syncEInvoiceStatus(FiscalInvoice $fiscal): void
    {
        $status = $this->provider->fetchEInvoiceStatus($fiscal);

        $fiscal->setEInvoiceStatus($status->eInvoiceStatus);
        if (null !== $status->spvId) {
            $fiscal->setSpvId($status->spvId);
        }
        if (null !== $status->error) {
            $fiscal->setEInvoiceError($status->error);
        }

        $this->em->flush();
    }

    /**
     * Issue a storno (correction invoice) reversing an issued invoice: a new
     * STORNO mirror with negated amounts, the original marked CANCELED.
     */
    public function storno(FiscalInvoice $original, string $reason): FiscalInvoice
    {
        if (FiscalInvoiceStatus::ISSUED !== $original->getStatus()) {
            throw EInvoicingException::business('Only an issued invoice can be reversed.');
        }

        $result = $this->provider->storno($original, $reason);
        if (!$result->success) {
            throw EInvoicingException::business('Provider storno failed: ' . ($result->error ?? 'unknown'));
        }

        $storno = (new FiscalInvoice())
            ->setUser($original->getUser())
            ->setKind(FiscalInvoiceKind::STORNO)
            ->setStornoOf($original)
            ->setStatus(FiscalInvoiceStatus::ISSUED)
            ->setProviderName($original->getProviderName())
            ->setSeries($result->stornoSeries ?? $original->getSeries())
            ->setNumber($result->stornoNumber)
            ->setCurrency($original->getCurrency())
            ->setIssuedAt(new \DateTimeImmutable())
            ->setSupplier($original->getSupplier() ?? throw new \LogicException('Original supplier snapshot missing.'))
            ->setBuyer($original->getBuyer() ?? throw new \LogicException('Original buyer snapshot missing.'))
            ->setNetTotal($this->negate($original->getNetTotal()))
            ->setVatTotal($this->negate($original->getVatTotal()))
            ->setGrossTotal($this->negate($original->getGrossTotal()));

        foreach ($original->getLines() as $line) {
            $storno->addLine((new FiscalInvoiceLine())
                ->setDescription('Storno: ' . $line->getDescription())
                ->setQuantity($line->getQuantity())
                ->setUnitPriceNet($this->negate($line->getUnitPriceNet()))
                ->setVatRate($line->getVatRate())
                ->setLineNet($this->negate($line->getLineNet()))
                ->setLineVat($this->negate($line->getLineVat()))
                ->setLineGross($this->negate($line->getLineGross())));
        }

        $original->setStatus(FiscalInvoiceStatus::CANCELED);

        $this->em->persist($storno);
        $this->em->flush();

        $this->auditLog->log(
            action: 'fiscal_invoice_storno',
            entityType: 'FiscalInvoice',
            entityId: (string) $storno->getId(),
            newData: ['stornoOf' => $original->getId(), 'reason' => $reason],
            category: AuditLogService::CATEGORY_BILLING,
        );

        return $storno;
    }

    private function negate(string $decimal): string
    {
        return number_format(-1 * (float) $decimal, 2, '.', '');
    }
}
