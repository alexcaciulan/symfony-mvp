<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\DTO\Billing\Netopia\NetopiaChargeResult;
use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceType;
use App\Repository\SubscriptionRepository;
use App\Service\Billing\InvoicingService;
use App\Service\Billing\Netopia\NetopiaException;
use App\Service\Billing\NetopiaPaymentGateway;
use App\Service\Billing\SubscriptionRenewalService;
use App\Service\Billing\SubscriptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SubscriptionRenewalServiceTest extends TestCase
{
    private SubscriptionRepository&MockObject $subscriptions;
    private SubscriptionService&MockObject $subscriptionService;
    private InvoicingService&MockObject $invoicing;
    private NetopiaPaymentGateway&MockObject $gateway;
    private MailerInterface&MockObject $mailer;

    protected function setUp(): void
    {
        $this->subscriptions = $this->createMock(SubscriptionRepository::class);
        $this->subscriptionService = $this->createMock(SubscriptionService::class);
        $this->invoicing = $this->createMock(InvoicingService::class);
        $this->gateway = $this->createMock(NetopiaPaymentGateway::class);
        $this->mailer = $this->createMock(MailerInterface::class);
    }

    private function service(): SubscriptionRenewalService
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('subject');

        return new SubscriptionRenewalService(
            $this->subscriptions,
            $this->subscriptionService,
            $this->invoicing,
            $this->gateway,
            $this->mailer,
            $translator,
            'from@test.com',
            'https://app.test',
        );
    }

    private function subscription(?\DateTimeImmutable $tokenExpiry): Subscription
    {
        return (new Subscription())
            ->setUser((new User())->setEmail('lawyer@test.com'))
            ->setRecurringToken('tok-abc')
            ->setRecurringTokenExpiresAt($tokenExpiry);
    }

    public function testChargesSuccessfulRenewal(): void
    {
        $sub = $this->subscription(new \DateTimeImmutable('+1 year'));
        $this->subscriptions->method('findDueForRenewal')->willReturn([$sub]);

        $this->subscriptionService->expects(self::once())->method('renewSubscription')->willReturn(new Invoice());
        $this->subscriptionService->expects(self::never())->method('markPastDue');
        $this->gateway->method('chargeRenewal')->willReturn(new NetopiaChargeResult(accepted: true, status: 3, ntpID: 'NTP-1'));
        $this->invoicing->expects(self::once())->method('setExternalReference')->with(self::isInstanceOf(Invoice::class), 'NTP-1');
        $this->mailer->expects(self::never())->method('send');

        $summary = $this->service()->renewDue(new \DateTimeImmutable());

        self::assertSame(1, $summary['charged']);
        self::assertSame(0, $summary['past_due']);
    }

    public function testDunsDeclinedCharge(): void
    {
        $sub = $this->subscription(new \DateTimeImmutable('+1 year'));
        $this->subscriptions->method('findDueForRenewal')->willReturn([$sub]);

        $this->subscriptionService->method('renewSubscription')->willReturn(new Invoice());
        $this->subscriptionService->expects(self::once())->method('markPastDue')->with($sub, 'charge_declined');
        $this->gateway->method('chargeRenewal')->willReturn(new NetopiaChargeResult(accepted: false, status: 12, ntpID: 'NTP-1'));
        $this->mailer->expects(self::once())->method('send');

        $summary = $this->service()->renewDue(new \DateTimeImmutable());

        self::assertSame(1, $summary['past_due']);
    }

    public function testDunsGatewayError(): void
    {
        $sub = $this->subscription(new \DateTimeImmutable('+1 year'));
        $this->subscriptions->method('findDueForRenewal')->willReturn([$sub]);

        $this->subscriptionService->method('renewSubscription')->willReturn(new Invoice());
        $this->subscriptionService->expects(self::once())->method('markPastDue')->with($sub, 'charge_error');
        $this->gateway->method('chargeRenewal')->willThrowException(new NetopiaException('boom', retryable: true));
        $this->mailer->expects(self::once())->method('send');

        $summary = $this->service()->renewDue(new \DateTimeImmutable());

        self::assertSame(1, $summary['past_due']);
    }

    public function testAsksReauthorizationForExpiredToken(): void
    {
        $sub = $this->subscription(new \DateTimeImmutable('-1 day'));
        $this->subscriptions->method('findDueForRenewal')->willReturn([$sub]);

        $this->subscriptionService->expects(self::never())->method('renewSubscription');
        $this->subscriptionService->expects(self::once())->method('markPastDue')->with($sub, 'token_expired');
        $this->gateway->expects(self::never())->method('chargeRenewal');
        $this->mailer->expects(self::once())->method('send');

        $summary = $this->service()->renewDue(new \DateTimeImmutable());

        self::assertSame(1, $summary['reauth']);
    }
}
