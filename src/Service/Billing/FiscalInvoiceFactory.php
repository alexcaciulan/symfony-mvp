<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\DTO\Billing\EInvoicing\EInvoiceCollect;
use App\DTO\Billing\EInvoicing\EInvoiceIssueRequest;
use App\DTO\Billing\EInvoicing\EInvoiceLineInput;
use App\DTO\Billing\PartySnapshot;
use App\Entity\FiscalInvoice;
use App\Entity\FiscalInvoiceLine;
use App\Entity\Invoice;
use App\Enum\InvoiceType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the local mirror ({@see FiscalInvoice}, DRAFT) and the normalized
 * provider request ({@see EInvoiceIssueRequest}) from a paid legacy
 * {@see Invoice}.
 *
 * Freezes the supplier snapshot (from config) and the buyer snapshot (from the
 * account holder). The legacy `Invoice.amount` is treated as the NET unit price;
 * VAT is added on top (B2B net pricing convention). Series/number come from the
 * provider at issuance, not from here.
 *
 * @phpstan-type SupplierConfig array{name: string, cui?: string, onrcNumber?: string, address?: string, iban?: string}
 */
final class FiscalInvoiceFactory
{
    /**
     * Payment recorded on the issued invoice. Gateway charges are card payments;
     * P2 will parameterize this from the actual payment method when bank transfer
     * ("Ordin de plata") becomes possible.
     */
    private const COLLECT_TYPE_CARD = 'Card';

    /**
     * @param SupplierConfig $invoiceSupplier
     */
    public function __construct(
        private readonly VatCalculator $vat,
        private readonly TranslatorInterface $translator,
        private readonly array $invoiceSupplier,
        private readonly int $invoiceVatRate,
        private readonly string $invoiceSeriesSubscription,
        private readonly string $invoiceSeriesCaseExtra,
        private readonly int $invoiceDueDays,
    ) {}

    /** Local mirror in DRAFT state, with snapshots, line, totals and dates set. */
    public function buildDraft(Invoice $invoice): FiscalInvoice
    {
        $now = new \DateTimeImmutable();

        $fiscal = (new FiscalInvoice())
            ->setInvoice($invoice)
            ->setUser($invoice->getUser())
            ->setSupplier($this->supplierSnapshot())
            ->setBuyer(PartySnapshot::fromUser($invoice->getUser()))
            ->setIssuedAt($now)
            ->setTaxPointDate($now)
            ->setDueAt($now->modify(sprintf('+%d days', $this->invoiceDueDays)));

        $vatRate = (string) $this->invoiceVatRate;
        $totals = $this->vat->lineTotals($invoice->getAmount(), '1', $vatRate);

        $line = (new FiscalInvoiceLine())
            ->setDescription($this->lineDescription($invoice))
            ->setQuantity('1.000')
            ->setUnitPriceNet($invoice->getAmount())
            ->setVatRate($vatRate)
            ->setLineNet($totals['net'])
            ->setLineVat($totals['vat'])
            ->setLineGross($totals['gross']);
        $fiscal->addLine($line);

        $breakdown = $this->vat->breakdown($fiscal->getLines());
        $fiscal->setNetTotal($breakdown->netTotal)
            ->setVatTotal($breakdown->vatTotal)
            ->setGrossTotal($breakdown->grossTotal);

        return $fiscal;
    }

    /** Normalized provider request derived from a built draft (already collected). */
    public function buildIssueRequest(FiscalInvoice $draft): EInvoiceIssueRequest
    {
        $invoice = $draft->getInvoice();
        if (null === $invoice) {
            throw new \LogicException('Cannot build an issue request for a draft without a source Invoice.');
        }

        $invoiceId = $invoice->getId()
            ?? throw new \LogicException('Cannot issue a fiscal invoice for an unpersisted Invoice (no id for the idempotency key).');

        $vatName = 0 === $this->invoiceVatRate ? 'SFDD' : 'Normala';

        $lines = [];
        foreach ($draft->getLines() as $line) {
            $lines[] = new EInvoiceLineInput(
                name: $line->getDescription(),
                unitPriceNet: $line->getUnitPriceNet(),
                quantity: $line->getQuantity(),
                vatPercentage: $line->getVatRate(),
                vatName: $vatName,
            );
        }

        return new EInvoiceIssueRequest(
            supplierCif: (string) ($this->invoiceSupplier['cui'] ?? ''),
            seriesName: $this->seriesFor($invoice),
            client: $draft->getBuyer() ?? PartySnapshot::fromUser($invoice->getUser()),
            lines: $lines,
            idempotencyKey: (string) $invoiceId,
            currency: $draft->getCurrency(),
            issueDate: $draft->getIssuedAt(),
            dueDate: $draft->getDueAt(),
            collect: new EInvoiceCollect(
                type: self::COLLECT_TYPE_CARD,
                value: $draft->getGrossTotal(),
                date: $draft->getIssuedAt() ?? new \DateTimeImmutable(),
            ),
        );
    }

    private function seriesFor(Invoice $invoice): string
    {
        return match ($invoice->getType()) {
            // A plan change bills a plan's monthly price, so it belongs to the
            // subscription series rather than a series of its own: keeping the
            // numbering continuous is what matters for the fiscal register.
            InvoiceType::SUBSCRIPTION, InvoiceType::PLAN_CHANGE => $this->invoiceSeriesSubscription,
            InvoiceType::CASE_EXTRA => $this->invoiceSeriesCaseExtra,
        };
    }

    private function supplierSnapshot(): PartySnapshot
    {
        return new PartySnapshot(
            name: $this->invoiceSupplier['name'],
            cui: $this->invoiceSupplier['cui'] ?? null,
            onrcNumber: $this->invoiceSupplier['onrcNumber'] ?? null,
            address: $this->invoiceSupplier['address'] ?? '',
            iban: $this->invoiceSupplier['iban'] ?? null,
        );
    }

    private function lineDescription(Invoice $invoice): string
    {
        return match ($invoice->getType()) {
            InvoiceType::SUBSCRIPTION => $this->translator->trans('invoice.line.subscription', [
                '%plan%' => $invoice->getSubscription()?->getPlan()->getName() ?? '',
            ]),
            InvoiceType::CASE_EXTRA => $this->translator->trans('invoice.line.case_extra', [
                '%case%' => $invoice->getLegalCase()?->getCaseNumber()
                    ?? ('#' . ($invoice->getLegalCase()?->getId() ?? $invoice->getId())),
            ]),
            // The target plan, not the subscription's current one: the change is
            // only applied on settlement, so at issuance the subscription still
            // carries the old plan.
            InvoiceType::PLAN_CHANGE => $this->translator->trans('invoice.line.plan_change', [
                '%plan%' => $invoice->getTargetPlan()?->getName() ?? '',
            ]),
        };
    }
}
