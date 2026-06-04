<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\Invoice;
use App\Service\Billing\StubPaymentGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class StubPaymentGatewayTest extends TestCase
{
    public function testStartCheckoutReturnsInternalCheckoutUrl(): void
    {
        $invoice = new Invoice();
        $ref = new \ReflectionProperty(Invoice::class, 'id');
        $ref->setValue($invoice, 42);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generate')
            ->with('app_subscription_checkout', ['invoiceId' => 42], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://app.test/subscription/checkout/42');

        $gateway = new StubPaymentGateway($urlGenerator);
        $session = $gateway->startCheckout($invoice);

        $this->assertSame('https://app.test/subscription/checkout/42', $session->url);
        $this->assertNull($session->externalId);
    }

    public function testHandleWebhookThrows(): void
    {
        $gateway = new StubPaymentGateway($this->createStub(UrlGeneratorInterface::class));

        $this->expectException(\LogicException::class);
        $gateway->handleWebhook(new Request());
    }
}
