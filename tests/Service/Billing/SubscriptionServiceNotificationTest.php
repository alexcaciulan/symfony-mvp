<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\Invoice;
use App\Entity\LegalCase;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceType;
use App\Enum\NotificationType;
use App\Enum\SubscriptionStatus;
use App\Repository\InvoiceRepository;
use App\Repository\PlanRepository;
use App\Repository\SubscriptionRepository;
use App\Service\AuditLogService;
use App\Service\Billing\InvoicingService;
use App\Service\Billing\SubscriptionService;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit coverage for the SLOT_OVERAGE fan-out in {@see SubscriptionService::consumeCaseSlot}:
 * billing a case beyond the plan's included slots dispatches exactly one
 * NotificationDispatch (right type, recipient, dedup key, no legalCase link),
 * while any non-overage outcome (from-plan, trial, no-subscription) dispatches nothing.
 */
#[AllowMockObjectsWithoutExpectations]
final class SubscriptionServiceNotificationTest extends TestCase
{
    private function user(): User
    {
        return (new User())->setEmail('lawyer@test.com');
    }

    private function plan(int $includedCases, bool $isTrial = false): Plan
    {
        return (new Plan())
            ->setName('plan')
            ->setPriceMonthly($isTrial ? '0.00' : '99.00')
            ->setIncludedCases($includedCases)
            ->setPricePerExtra('25.00')
            ->setIsActive(true)
            ->setIsTrial($isTrial);
    }

    private function subscription(User $user, Plan $plan, SubscriptionStatus $status, int $casesConsumed): Subscription
    {
        return (new Subscription())
            ->setUser($user)
            ->setPlan($plan)
            ->setStatus($status)
            ->setCasesConsumed($casesConsumed)
            ->setCurrentPeriodStart(new \DateTimeImmutable('-1 day'))
            ->setCurrentPeriodEnd(new \DateTimeImmutable('+30 days'));
    }

    private function case(User $user): LegalCase
    {
        return (new LegalCase())->setUser($user);
    }

    private function overageInvoice(int $id, User $user): Invoice
    {
        $invoice = (new Invoice())
            ->setUser($user)
            ->setType(InvoiceType::CASE_EXTRA)
            ->setAmount('25.00');
        (new \ReflectionProperty(Invoice::class, 'id'))->setValue($invoice, $id);

        return $invoice;
    }

    /**
     * Builds the service with an EM whose transaction wrapper runs the closure
     * inline, the current subscription pre-resolved, and a stub invoicing service
     * returning the given overage invoice (null when no overage is expected).
     */
    private function service(
        ?Subscription $current,
        NotificationDispatcherInterface $notifier,
        ?Invoice $overageInvoice = null,
    ): SubscriptionService {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $work) => $work());

        $subscriptions = $this->createMock(SubscriptionRepository::class);
        $subscriptions->method('findCurrentForUser')->willReturn($current);

        $invoicing = $this->createMock(InvoicingService::class);
        if (null !== $overageInvoice) {
            $invoicing->method('createCaseExtraInvoice')->willReturn($overageInvoice);
        }

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new SubscriptionService(
            $em,
            $subscriptions,
            $this->createMock(PlanRepository::class),
            $invoicing,
            $this->createMock(InvoiceRepository::class),
            $this->createMock(AuditLogService::class),
            $notifier,
            $translator,
        );
    }

    public function testOverageDispatchesSlotOverageOnce(): void
    {
        $user = $this->user();
        $plan = $this->plan(includedCases: 5);
        $sub = $this->subscription($user, $plan, SubscriptionStatus::ACTIVE, casesConsumed: 5);
        $invoice = $this->overageInvoice(77, $user);

        $dispatchCount = 0;
        $captured = null;
        $notifier = $this->createMock(NotificationDispatcherInterface::class);
        // Reader closure captures by reference: an fn() arrow would freeze the null.
        $notifier->method('dispatch')->willReturnCallback(function (NotificationDispatch $d) use (&$dispatchCount, &$captured): void {
            ++$dispatchCount;
            $captured = $d;
        });

        $service = $this->service($sub, $notifier, $invoice);
        $service->consumeCaseSlot($this->case($user));

        self::assertSame(1, $dispatchCount);
        self::assertInstanceOf(NotificationDispatch::class, $captured);
        self::assertSame(NotificationType::SLOT_OVERAGE, $captured->type);
        self::assertSame($user, $captured->user);
        self::assertNull($captured->legalCase);
        self::assertSame('slot_overage:77', $captured->dedupKey);
    }

    public function testConsumedFromPlanDispatchesNothing(): void
    {
        $user = $this->user();
        $plan = $this->plan(includedCases: 5);
        $sub = $this->subscription($user, $plan, SubscriptionStatus::ACTIVE, casesConsumed: 2);

        $dispatchCount = 0;
        $notifier = $this->createMock(NotificationDispatcherInterface::class);
        $notifier->method('dispatch')->willReturnCallback(function (NotificationDispatch $d) use (&$dispatchCount): void {
            ++$dispatchCount;
        });

        $service = $this->service($sub, $notifier);
        $service->consumeCaseSlot($this->case($user));

        self::assertSame(0, $dispatchCount);
    }

    public function testTrialConsumedDispatchesNothing(): void
    {
        $user = $this->user();
        $plan = $this->plan(includedCases: 2, isTrial: true);
        $sub = $this->subscription($user, $plan, SubscriptionStatus::TRIAL, casesConsumed: 0);

        $dispatchCount = 0;
        $notifier = $this->createMock(NotificationDispatcherInterface::class);
        $notifier->method('dispatch')->willReturnCallback(function (NotificationDispatch $d) use (&$dispatchCount): void {
            ++$dispatchCount;
        });

        $service = $this->service($sub, $notifier);
        $service->consumeCaseSlot($this->case($user));

        self::assertSame(0, $dispatchCount);
    }

    public function testNoSubscriptionDispatchesNothing(): void
    {
        $user = $this->user();

        $dispatchCount = 0;
        $notifier = $this->createMock(NotificationDispatcherInterface::class);
        $notifier->method('dispatch')->willReturnCallback(function (NotificationDispatch $d) use (&$dispatchCount): void {
            ++$dispatchCount;
        });

        $service = $this->service(null, $notifier);
        $service->consumeCaseSlot($this->case($user));

        self::assertSame(0, $dispatchCount);
    }
}
