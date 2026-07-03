<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing\EInvoicing;

use App\DTO\Billing\EInvoicing\EInvoiceCancelResult;
use App\DTO\Billing\EInvoicing\EInvoiceIssueRequest;
use App\DTO\Billing\EInvoicing\EInvoiceIssueResult;
use App\DTO\Billing\EInvoicing\EInvoiceLineInput;
use App\DTO\Billing\EInvoicing\EInvoiceStatusResult;
use App\DTO\Billing\PartySnapshot;
use App\Entity\AppSetting;
use App\Entity\FiscalInvoice;
use App\Enum\EInvoiceStatus;
use App\Repository\AppSettingRepository;
use App\Service\Billing\EInvoicing\EInvoicingProviderInterface;
use App\Service\Billing\EInvoicing\EInvoicingProviderResolver;
use PHPUnit\Framework\TestCase;

class EInvoicingProviderResolverTest extends TestCase
{
    private function provider(string $name): EInvoicingProviderInterface
    {
        return new class($name) implements EInvoicingProviderInterface {
            public function __construct(private string $name) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function issue(EInvoiceIssueRequest $request): EInvoiceIssueResult
            {
                return new EInvoiceIssueResult($this->name, 'S', '1');
            }

            public function fetchEInvoiceStatus(FiscalInvoice $invoice): EInvoiceStatusResult
            {
                return new EInvoiceStatusResult(EInvoiceStatus::NOT_APPLICABLE);
            }

            public function storno(FiscalInvoice $invoice, string $reason): EInvoiceCancelResult
            {
                return new EInvoiceCancelResult(true);
            }

            public function getPdf(FiscalInvoice $invoice): ?string
            {
                return null;
            }

            public function isEFacturaEnabled(): bool
            {
                return false;
            }
        };
    }

    private function request(): EInvoiceIssueRequest
    {
        return new EInvoiceIssueRequest(
            supplierCif: 'RO1',
            seriesName: 'LEX',
            client: new PartySnapshot(name: 'X'),
            lines: [new EInvoiceLineInput('l', '1.00', '1.000', '19')],
            idempotencyKey: 'k',
        );
    }

    public function testResolvesProviderFromDbSetting(): void
    {
        $settings = $this->createMock(AppSettingRepository::class);
        $settings->method('get')->with(AppSetting::KEY_EINVOICE_PROVIDER)->willReturn('oblio');

        $resolver = new EInvoicingProviderResolver([$this->provider('stub'), $this->provider('oblio')], $settings, 'stub');

        $this->assertSame('oblio', $resolver->issue($this->request())->providerName);
    }

    public function testFallsBackToDefaultWhenSettingMissing(): void
    {
        $settings = $this->createMock(AppSettingRepository::class);
        $settings->method('get')->willReturn(null);

        $resolver = new EInvoicingProviderResolver([$this->provider('stub'), $this->provider('oblio')], $settings, 'stub');

        $this->assertSame('stub', $resolver->issue($this->request())->providerName);
    }

    public function testThrowsOnUnknownProvider(): void
    {
        $settings = $this->createMock(AppSettingRepository::class);
        $settings->method('get')->willReturn('nope');

        $resolver = new EInvoicingProviderResolver([$this->provider('stub')], $settings, 'stub');

        $this->expectException(\RuntimeException::class);
        $resolver->issue($this->request());
    }
}
