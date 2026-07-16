<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\DTO\Billing\Netopia\NetopiaChargeResult;
use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceType;
use App\Enum\NotificationType;
use App\Repository\SubscriptionRepository;
use App\Service\Billing\InvoicingService;
use App\Service\Billing\Netopia\NetopiaException;
use App\Service\Billing\NetopiaPaymentGateway;
use App\Service\Billing\SubscriptionRenewalService;
use App\Service\Billing\SubscriptionService;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class SubscriptionRenewalServiceTest extends TestCase
{
    private SubscriptionRepository&MockObject $subscriptions;
    private SubscriptionService&MockObject $subscriptionService;
    private InvoicingService&MockObject $invoicing;
    private NetopiaPaymentGateway&MockObject $gateway;
    private NotificationDispatcherInterface&MockObject $dispatcher;

    protected function setUp(): void
    {
        $this->subscriptions = $this->createMock(SubscriptionRepository::class);
        $this->subscriptionService = $this->createMock(SubscriptionService::class);
        $this->invoicing = $this->createMock(InvoicingService::class);
        $this->gateway = $this->createMock(NetopiaPaymentGateway::class);
        $this->dispatcher = $this->createMock(NotificationDispatcherInterface::class);
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
            $this->dispatcher,
            $translator,
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
        $sub = $this->subscription(new \DateTimeImmutable('2027-07-15'));
        $this->subscriptions->method('findDueForRenewal')->willReturn([$sub]);

        $this->subscriptionService->expects(self::once())->method('renewSubscription')->willReturn(new Invoice());
        $this->subscriptionService->expects(self::never())->method('markPastDue');
        $this->gateway->method('chargeRenewal')->willReturn(new NetopiaChargeResult(accepted: true, status: 3, ntpID: 'NTP-1'));
        $this->invoicing->expects(self::once())->method('setExternalReference')->with(self::isInstanceOf(Invoice::class), 'NTP-1');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $summary = $this->service()->renewDue(new \DateTimeImmutable());

        self::assertSame(1, $summary['charged']);
        self::assertSame(0, $summary['past_due']);
    }

    public function testDunsDeclinedCharge(): void
    {
        $sub = $this->subscription(new \DateTimeImmutable('2027-07-15'));
        $this->subscriptions->method('findDueForRenewal')->willReturn([$sub]);

        $this->subscriptionService->method('renewSubscription')->willReturn(new Invoice());
        $this->subscriptionService->expects(self::once())->method('markPastDue')->with($sub, 'charge_declined');
        $this->gateway->method('chargeRenewal')->willReturn(new NetopiaChargeResult(accepted: false, status: 12, ntpID: 'NTP-1'));
        $captured = $this->captureDispatch();

        $on = new \DateTimeImmutable('2026-07-15');
        $summary = $this->service()->renewDue($on);

        self::assertSame(1, $summary['past_due']);
        self::assertInstanceOf(NotificationDispatch::class, $captured());
        self::assertSame(NotificationType::PAYMENT_FAILED, $captured()->type);
        self::assertSame('emails/subscription_action_required.html.twig', $captured()->emailTemplate);
        // Reason for a declined card is 'charge_failed' (shared with gateway errors).
        self::assertSame('dunning:0:charge_failed:2026-07-15', $captured()->dedupKey);
    }

    public function testDunsGatewayError(): void
    {
        $sub = $this->subscription(new \DateTimeImmutable('2027-07-15'));
        $this->subscriptions->method('findDueForRenewal')->willReturn([$sub]);

        $this->subscriptionService->method('renewSubscription')->willReturn(new Invoice());
        $this->subscriptionService->expects(self::once())->method('markPastDue')->with($sub, 'charge_error');
        $this->gateway->method('chargeRenewal')->willThrowException(new NetopiaException('boom', retryable: true));
        $captured = $this->captureDispatch();

        $on = new \DateTimeImmutable('2026-07-15');
        $summary = $this->service()->renewDue($on);

        self::assertSame(1, $summary['past_due']);
        self::assertInstanceOf(NotificationDispatch::class, $captured());
        self::assertSame(NotificationType::PAYMENT_FAILED, $captured()->type);
        self::assertSame('emails/subscription_action_required.html.twig', $captured()->emailTemplate);
        self::assertSame('dunning:0:charge_failed:2026-07-15', $captured()->dedupKey);
    }

    public function testAsksReauthorizationForExpiredToken(): void
    {
        $sub = $this->subscription(new \DateTimeImmutable('2026-07-14'));
        $this->subscriptions->method('findDueForRenewal')->willReturn([$sub]);

        $this->subscriptionService->expects(self::never())->method('renewSubscription');
        $this->subscriptionService->expects(self::once())->method('markPastDue')->with($sub, 'token_expired');
        $this->gateway->expects(self::never())->method('chargeRenewal');
        $captured = $this->captureDispatch();

        $on = new \DateTimeImmutable('2026-07-15');
        $summary = $this->service()->renewDue($on);

        self::assertSame(1, $summary['reauth']);
        self::assertInstanceOf(NotificationDispatch::class, $captured());
        self::assertSame(NotificationType::TOKEN_EXPIRED, $captured()->type);
        self::assertSame('emails/subscription_action_required.html.twig', $captured()->emailTemplate);
        self::assertSame('dunning:0:token_expired:2026-07-15', $captured()->dedupKey);
    }

    /**
     * D8 regression: the dunning email must route to the template that actually
     * exists at templates/emails/subscription_action_required.html.twig (plural
     * 'emails/'). The old direct-mail path used the singular 'email/' path, whose
     * TemplatedEmail render threw and was swallowed, so no dunning mail ever left.
     */
    public function testDunningDispatchUsesExistingTemplatePath(): void
    {
        $sub = $this->subscription(new \DateTimeImmutable('2026-07-14'));
        $this->subscriptions->method('findDueForRenewal')->willReturn([$sub]);
        $this->subscriptionService->method('markPastDue');
        $captured = $this->captureDispatch();

        $this->service()->renewDue(new \DateTimeImmutable('2026-07-15'));

        self::assertInstanceOf(NotificationDispatch::class, $captured());
        self::assertSame('emails/subscription_action_required.html.twig', $captured()->emailTemplate);
        self::assertFileExists(
            \dirname(__DIR__, 3) . '/templates/' . $captured()->emailTemplate,
            'The dunning template path must resolve to a real Twig file.',
        );
    }

    public function testBatchStopsCleanlyWhenARenewalThrows(): void
    {
        // A persistence failure inside renewOne (e.g. a closed EntityManager) must
        // not crash the whole batch: the loop stops and the next cron run resumes.
        $sub1 = $this->subscription(new \DateTimeImmutable('2026-07-14'));
        $sub2 = $this->subscription(new \DateTimeImmutable('2026-07-14'));
        $this->subscriptions->method('findDueForRenewal')->willReturn([$sub1, $sub2]);
        $this->subscriptionService->method('markPastDue')->willThrowException(new \RuntimeException('EM closed'));

        $summary = $this->service()->renewDue(new \DateTimeImmutable('2026-07-15'));

        // Did not propagate; stopped after the first subscription (the second is
        // left untouched for the next run).
        self::assertSame(1, $summary['processed']);
    }

    /**
     * Returns a closure yielding the single NotificationDispatch handed to the
     * dispatcher (null until dispatch runs). Lets each test read back the full
     * dispatch: type, template path, and dedup key.
     *
     * @return \Closure(): ?NotificationDispatch
     */
    private function captureDispatch(): \Closure
    {
        $captured = null;
        $this->dispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(function (NotificationDispatch $d) use (&$captured): void {
                $captured = $d;
            });

        // Capture by reference: an arrow fn would snapshot the null value at
        // definition time, before dispatch() runs.
        return function () use (&$captured): ?NotificationDispatch {
            return $captured;
        };
    }
}
