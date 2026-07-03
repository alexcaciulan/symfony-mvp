<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Repository\InvoiceRepository;
use App\Service\Billing\Netopia\NetopiaApiClient;
use App\Service\Billing\Netopia\NetopiaException;
use App\Service\Billing\InvoicingService;
use App\Service\Billing\PaymentReconciliationService;
use PHPUnit\Framework\TestCase;

final class PaymentReconciliationServiceTest extends TestCase
{
    private function invoice(): Invoice
    {
        return (new Invoice())
            ->setUser((new User())->setEmail('lawyer@test.com'))
            ->setType(InvoiceType::SUBSCRIPTION)
            ->setAmount('99.00')
            ->setStatus(InvoiceStatus::PENDING)
            ->setExternalId('NTP-1');
    }

    private function client(): NetopiaApiClient
    {
        $client = $this->createMock(NetopiaApiClient::class);
        $client->method('isPaidStatus')->willReturnCallback(static fn (int $s): bool => in_array($s, [3, 5], true));

        return $client;
    }

    public function testSettlesInvoiceReportedPaid(): void
    {
        $invoice = $this->invoice();

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('findStalePending')->willReturn([$invoice]);

        $client = $this->client();
        $client->method('fetchStatus')->with('NTP-1')->willReturn(3);

        $invoicing = $this->createMock(InvoicingService::class);
        $invoicing->expects(self::once())->method('markPaid')->with($invoice, 'NTP-1', 'reconciliation');

        $summary = (new PaymentReconciliationService($invoices, $client, $invoicing))->reconcile(new \DateTimeImmutable());

        self::assertSame(1, $summary['settled']);
        self::assertSame(0, $summary['still_pending']);
    }

    public function testLeavesStillPendingInvoice(): void
    {
        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('findStalePending')->willReturn([$this->invoice()]);

        $client = $this->client();
        $client->method('fetchStatus')->willReturn(15);

        $invoicing = $this->createMock(InvoicingService::class);
        $invoicing->expects(self::never())->method('markPaid');

        $summary = (new PaymentReconciliationService($invoices, $client, $invoicing))->reconcile(new \DateTimeImmutable());

        self::assertSame(1, $summary['still_pending']);
    }

    public function testCountsGatewayErrors(): void
    {
        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('findStalePending')->willReturn([$this->invoice()]);

        $client = $this->client();
        $client->method('fetchStatus')->willThrowException(new NetopiaException('boom', retryable: true));

        $invoicing = $this->createMock(InvoicingService::class);
        $invoicing->expects(self::never())->method('markPaid');

        $summary = (new PaymentReconciliationService($invoices, $client, $invoicing))->reconcile(new \DateTimeImmutable());

        self::assertSame(1, $summary['errors']);
    }
}
