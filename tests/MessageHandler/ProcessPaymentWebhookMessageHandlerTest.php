<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Message\ProcessPaymentWebhookMessage;
use App\MessageHandler\ProcessPaymentWebhookMessageHandler;
use App\Repository\InvoiceRepository;
use App\Service\Billing\InvoicingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProcessPaymentWebhookMessageHandlerTest extends TestCase
{
    private function invoice(InvoiceStatus $status, ?Subscription $subscription = null): Invoice
    {
        return (new Invoice())
            ->setUser((new User())->setEmail('lawyer@test.com'))
            ->setType(InvoiceType::SUBSCRIPTION)
            ->setAmount('99.00')
            ->setStatus($status)
            ->setSubscription($subscription);
    }

    private function handler(InvoiceRepository $invoices, InvoicingService $invoicing): ProcessPaymentWebhookMessageHandler
    {
        return new ProcessPaymentWebhookMessageHandler($invoices, $invoicing, $this->createMock(EntityManagerInterface::class));
    }

    public function testSettlesPendingInvoice(): void
    {
        $invoice = $this->invoice(InvoiceStatus::PENDING);

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->with(10)->willReturn($invoice);

        $invoicing = $this->createMock(InvoicingService::class);
        $invoicing->expects(self::once())->method('markPaid')->with($invoice, 'NTP-10', 'webhook');

        $this->handler($invoices, $invoicing)(new ProcessPaymentWebhookMessage(
            invoiceId: 10, paid: true, externalRef: 'NTP-10', status: 3,
        ));
    }

    public function testDoesNotSettleAlreadyPaidInvoice(): void
    {
        $invoice = $this->invoice(InvoiceStatus::PAID);

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $invoicing = $this->createMock(InvoicingService::class);
        $invoicing->expects(self::never())->method('markPaid');

        $this->handler($invoices, $invoicing)(new ProcessPaymentWebhookMessage(
            invoiceId: 10, paid: true, externalRef: 'NTP-10', status: 3,
        ));
    }

    public function testIgnoresNotPaidNotification(): void
    {
        $invoice = $this->invoice(InvoiceStatus::PENDING);

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $invoicing = $this->createMock(InvoicingService::class);
        $invoicing->expects(self::never())->method('markPaid');

        $this->handler($invoices, $invoicing)(new ProcessPaymentWebhookMessage(
            invoiceId: 10, paid: false, externalRef: null, status: 15,
        ));
    }

    public function testHandlesMissingInvoiceGracefully(): void
    {
        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn(null);

        $invoicing = $this->createMock(InvoicingService::class);
        $invoicing->expects(self::never())->method('markPaid');

        $this->handler($invoices, $invoicing)(new ProcessPaymentWebhookMessage(
            invoiceId: 999, paid: true, externalRef: 'X', status: 3,
        ));
    }

    public function testSavesRecurringTokenOnSubscription(): void
    {
        $subscription = (new Subscription())->setUser((new User())->setEmail('l@test.com'));
        $invoice = $this->invoice(InvoiceStatus::PENDING, $subscription);

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $invoicing = $this->createMock(InvoicingService::class);
        $invoicing->expects(self::once())->method('markPaid');

        $this->handler($invoices, $invoicing)(new ProcessPaymentWebhookMessage(
            invoiceId: 10, paid: true, externalRef: 'NTP-10', status: 3,
            token: 'save-tok', tokenExpiresAt: '12/28', cardMask: '4111 **** 1111',
        ));

        self::assertSame('save-tok', $subscription->getRecurringToken());
        self::assertSame('4111 **** 1111', $subscription->getCardMask());
        self::assertNotNull($subscription->getRecurringTokenExpiresAt());
        self::assertSame('2028', $subscription->getRecurringTokenExpiresAt()->format('Y'));
    }

    public function testSavesTokenFromNotYetPaidIpn(): void
    {
        // Netopia delivers the token once, at (pre)approval, often BEFORE the paid
        // IPN. It must be captured even though the invoice is not settled here.
        $subscription = (new Subscription())->setUser((new User())->setEmail('l@test.com'));
        $invoice = $this->invoice(InvoiceStatus::PENDING, $subscription);

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $invoicing = $this->createMock(InvoicingService::class);
        $invoicing->expects(self::never())->method('markPaid');

        $this->handler($invoices, $invoicing)(new ProcessPaymentWebhookMessage(
            invoiceId: 10, paid: false, externalRef: 'NTP-10', status: 15,
            token: 'pre-approval-tok', cardMask: '4111 **** 1111',
        ));

        self::assertSame('pre-approval-tok', $subscription->getRecurringToken());
    }
}
