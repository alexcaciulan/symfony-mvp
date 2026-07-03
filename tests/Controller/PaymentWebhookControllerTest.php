<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\DTO\Billing\WebhookResult;
use App\Service\Billing\Netopia\NetopiaException;
use App\Service\Billing\PaymentGatewayInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PaymentWebhookControllerTest extends WebTestCase
{
    public function testValidIpnAcksAndDispatchesSettlement(): void
    {
        $client = static::createClient();

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('handleWebhook')->willReturn(
            new WebhookResult(invoiceId: 5, paid: true, externalRef: 'NTP-5', status: 3),
        );
        self::getContainer()->set(PaymentGatewayInterface::class, $gateway);

        // No authentication: proves the route sits outside the firewall.
        $client->request('POST', '/webhook/netopia', content: 'signed-jwt');

        self::assertResponseIsSuccessful();

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
    }

    public function testInvalidSignatureIsRejectedWithoutDispatch(): void
    {
        $client = static::createClient();

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('handleWebhook')->willThrowException(new NetopiaException('bad signature'));
        self::getContainer()->set(PaymentGatewayInterface::class, $gateway);

        $client->request('POST', '/webhook/netopia', content: 'tampered');

        self::assertResponseStatusCodeSame(400);

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(0, $transport->getSent());
    }

    public function testUnparseableOrderIdIsAckedWithoutDispatch(): void
    {
        $client = static::createClient();

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('handleWebhook')->willReturn(
            new WebhookResult(invoiceId: null, paid: true, externalRef: 'NTP-x', status: 3),
        );
        self::getContainer()->set(PaymentGatewayInterface::class, $gateway);

        $client->request('POST', '/webhook/netopia', content: 'signed-jwt');

        self::assertResponseIsSuccessful();

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(0, $transport->getSent());
    }
}
