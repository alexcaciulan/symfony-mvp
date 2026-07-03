<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\DTO\Billing\Netopia\NetopiaIpnResult;
use App\DTO\Billing\Netopia\NetopiaStartResult;
use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\InvoiceType;
use App\Service\Billing\Netopia\NetopiaApiClient;
use App\Service\Billing\Netopia\NetopiaException;
use App\Service\Billing\NetopiaPaymentGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class NetopiaPaymentGatewayTest extends TestCase
{
    private function invoiceWithId(int $id): Invoice
    {
        $invoice = (new Invoice())
            ->setUser((new User())->setEmail('lawyer@test.com'))
            ->setType(InvoiceType::SUBSCRIPTION)
            ->setAmount('99.00');

        $ref = new \ReflectionProperty(Invoice::class, 'id');
        $ref->setValue($invoice, $id);

        return $invoice;
    }

    public function testStartCheckoutMapsToCheckoutSession(): void
    {
        $client = $this->createMock(NetopiaApiClient::class);
        $client->expects(self::once())
            ->method('startPayment')
            ->willReturn(new NetopiaStartResult('https://sandbox.netopia/pay/abc', 'NTP-1', 15));

        $session = (new NetopiaPaymentGateway($client))->startCheckout($this->invoiceWithId(5));

        self::assertSame('https://sandbox.netopia/pay/abc', $session->url);
        self::assertSame('NTP-1', $session->externalId);
    }

    public function testStartCheckoutRejectsUnpersistedInvoice(): void
    {
        $client = $this->createMock(NetopiaApiClient::class);

        $this->expectException(NetopiaException::class);
        (new NetopiaPaymentGateway($client))->startCheckout(new Invoice());
    }

    public function testHandleWebhookMapsIpnToWebhookResult(): void
    {
        $client = $this->createMock(NetopiaApiClient::class);
        $client->method('verifyIpn')->willReturn(new NetopiaIpnResult(
            orderId: 'INV-42-xyz',
            status: 3,
            ntpID: 'NTP-42',
            paid: true,
            token: 'save-tok',
            tokenExpiresAt: '12/28',
            cardMask: '4111 **** 1111',
        ));

        $result = (new NetopiaPaymentGateway($client))->handleWebhook(Request::create('/webhook/netopia', 'POST'));

        self::assertSame(42, $result->invoiceId);
        self::assertTrue($result->paid);
        self::assertSame('NTP-42', $result->externalRef);
        self::assertSame('save-tok', $result->token);
        self::assertSame('12/28', $result->tokenExpiresAt);
        self::assertSame('4111 **** 1111', $result->cardMask);
    }

    public function testOrderIdRoundTrips(): void
    {
        $orderId = NetopiaPaymentGateway::buildOrderId(123);

        self::assertStringStartsWith('INV-123-', $orderId);
        self::assertSame(123, NetopiaPaymentGateway::parseInvoiceId($orderId));
    }

    public function testParseInvoiceIdReturnsNullForGarbage(): void
    {
        self::assertNull(NetopiaPaymentGateway::parseInvoiceId('not-an-order-id'));
        self::assertNull(NetopiaPaymentGateway::parseInvoiceId('INV-abc-x'));
    }
}
