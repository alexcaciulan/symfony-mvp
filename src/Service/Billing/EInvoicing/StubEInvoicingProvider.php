<?php

declare(strict_types=1);

namespace App\Service\Billing\EInvoicing;

use App\DTO\Billing\EInvoicing\EInvoiceCancelResult;
use App\DTO\Billing\EInvoicing\EInvoiceIssueRequest;
use App\DTO\Billing\EInvoicing\EInvoiceIssueResult;
use App\DTO\Billing\EInvoicing\EInvoiceStatusResult;
use App\Entity\FiscalInvoice;
use App\Enum\EInvoiceStatus;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Non-production provider for local dev / staging / tests. Allocates a fake but
 * deterministic series/number (derived from the idempotency key) and never
 * touches the network or ANAF.
 *
 * Hard env guard: refuses to run in prod, so it can never silently issue a fake
 * "fiscal" invoice on a production deployment.
 */
#[AutoconfigureTag('app.einvoicing_provider')]
final class StubEInvoicingProvider implements EInvoicingProviderInterface
{
    public function __construct(
        private readonly string $appEnv,
    ) {}

    public function getName(): string
    {
        return 'stub';
    }

    public function issue(EInvoiceIssueRequest $request): EInvoiceIssueResult
    {
        $this->guardEnv();

        $number = str_pad((string) (abs(crc32($request->idempotencyKey)) % 100000), 5, '0', STR_PAD_LEFT);

        return new EInvoiceIssueResult(
            providerName: $this->getName(),
            series: $request->seriesName,
            number: $number,
            providerInvoiceId: 'stub-' . $request->idempotencyKey,
            eInvoiceStatus: EInvoiceStatus::NOT_APPLICABLE,
        );
    }

    public function fetchEInvoiceStatus(FiscalInvoice $invoice): EInvoiceStatusResult
    {
        $this->guardEnv();

        return new EInvoiceStatusResult(EInvoiceStatus::NOT_APPLICABLE);
    }

    public function storno(FiscalInvoice $invoice, string $reason): EInvoiceCancelResult
    {
        $this->guardEnv();

        return new EInvoiceCancelResult(success: true);
    }

    public function getPdf(FiscalInvoice $invoice): ?string
    {
        return null;
    }

    public function isEFacturaEnabled(): bool
    {
        return false;
    }

    private function guardEnv(): void
    {
        if ('prod' === $this->appEnv) {
            throw new \LogicException('StubEInvoicingProvider must not run in production. Configure a real provider (oblio/smartbill).');
        }
    }
}
