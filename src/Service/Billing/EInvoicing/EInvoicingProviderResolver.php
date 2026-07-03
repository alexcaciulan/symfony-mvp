<?php

declare(strict_types=1);

namespace App\Service\Billing\EInvoicing;

use App\DTO\Billing\EInvoicing\EInvoiceCancelResult;
use App\DTO\Billing\EInvoicing\EInvoiceIssueRequest;
use App\DTO\Billing\EInvoicing\EInvoiceIssueResult;
use App\DTO\Billing\EInvoicing\EInvoiceStatusResult;
use App\Entity\AppSetting;
use App\Entity\FiscalInvoice;
use App\Repository\AppSettingRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Delegating provider: resolves the active adapter per call from the
 * admin-editable `einvoice_provider` setting (falling back to the
 * EINVOICE_PROVIDER env default), so the provider can be switched live from the
 * admin UI without a restart.
 *
 * The application injects this as {@see EInvoicingProviderInterface}; it never
 * sees the concrete adapters. This class is NOT tagged, so it does not appear in
 * its own provider iterator.
 */
final class EInvoicingProviderResolver implements EInvoicingProviderInterface
{
    /** @var array<string, EInvoicingProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<EInvoicingProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.einvoicing_provider')] iterable $providers,
        private readonly AppSettingRepository $settings,
        private readonly string $defaultProvider,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }
    }

    public function getName(): string
    {
        return $this->active()->getName();
    }

    public function issue(EInvoiceIssueRequest $request): EInvoiceIssueResult
    {
        return $this->active()->issue($request);
    }

    public function fetchEInvoiceStatus(FiscalInvoice $invoice): EInvoiceStatusResult
    {
        return $this->active()->fetchEInvoiceStatus($invoice);
    }

    public function storno(FiscalInvoice $invoice, string $reason): EInvoiceCancelResult
    {
        return $this->active()->storno($invoice, $reason);
    }

    public function getPdf(FiscalInvoice $invoice): ?string
    {
        return $this->active()->getPdf($invoice);
    }

    public function isEFacturaEnabled(): bool
    {
        return $this->active()->isEFacturaEnabled();
    }

    private function active(): EInvoicingProviderInterface
    {
        // Read per call so an admin toggle takes effect without a restart. The
        // Doctrine identity map absorbs repeated reads within one request, so
        // this is one DB hit per EM lifecycle, not per call. Caveat: a
        // long-running worker that clears the EM picks up a toggle change on the
        // next read; one that never clears keeps the value until restart.
        $name = $this->settings->get(AppSetting::KEY_EINVOICE_PROVIDER) ?: $this->defaultProvider;

        return $this->providers[$name]
            ?? throw new \RuntimeException(sprintf('Unknown e-invoicing provider "%s". Available: %s.', $name, implode(', ', array_keys($this->providers))));
    }
}
