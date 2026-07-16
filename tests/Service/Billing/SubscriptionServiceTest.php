<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\InvoiceType;
use App\Enum\NotificationType;
use App\Enum\SubscriptionSlotConsumptionOutcome;
use App\Enum\SubscriptionStatus;
use App\Service\Billing\SubscriptionService;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SubscriptionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SubscriptionService $service;
    private User $user;
    private string $testPrefix;
    private int $planCounter = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(SubscriptionService::class);
        $this->testPrefix = 'billing-sub-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createPlan(int $includedCases, bool $isTrial = false, string $priceMonthly = '99.00', string $pricePerExtra = '25.00'): Plan
    {
        $plan = new Plan();
        $plan->setName($this->testPrefix . '-plan-' . (++$this->planCounter));
        $plan->setPriceMonthly($priceMonthly);
        $plan->setIncludedCases($includedCases);
        $plan->setPricePerExtra($pricePerExtra);
        $plan->setIsActive(true);
        $plan->setIsTrial($isTrial);
        $this->em->persist($plan);

        return $plan;
    }

    private function createSubscription(
        Plan $plan,
        SubscriptionStatus $status,
        int $casesConsumed,
        string $periodEndModifier = '+30 days',
    ): Subscription {
        $now = new \DateTimeImmutable();
        $sub = new Subscription();
        $sub->setUser($this->user);
        $sub->setPlan($plan);
        $sub->setStatus($status);
        $sub->setCasesConsumed($casesConsumed);
        $sub->setCurrentPeriodStart($now->modify('-1 day'));
        $sub->setCurrentPeriodEnd($now->modify($periodEndModifier));
        $this->em->persist($sub);
        $this->em->flush();

        return $sub;
    }

    private function createCase(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus(CaseStatus::AMIABIL);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    public function testConsumeFromPlanWhenActiveWithFreeSlot(): void
    {
        $plan = $this->createPlan(includedCases: 5);
        $sub = $this->createSubscription($plan, SubscriptionStatus::ACTIVE, casesConsumed: 2);

        $result = $this->service->consumeCaseSlot($this->createCase());

        $this->assertSame(SubscriptionSlotConsumptionOutcome::CONSUMED_FROM_PLAN, $result->outcome);
        $this->assertTrue($result->allowsCaseActivation);
        $this->assertNull($result->invoice);
        $this->assertSame(3, $sub->getCasesConsumed());
    }

    public function testActiveExhaustedCreatesOverageInvoice(): void
    {
        $plan = $this->createPlan(includedCases: 5, pricePerExtra: '25.00');
        $sub = $this->createSubscription($plan, SubscriptionStatus::ACTIVE, casesConsumed: 5);

        $result = $this->service->consumeCaseSlot($this->createCase());

        $this->assertSame(SubscriptionSlotConsumptionOutcome::OVERAGE_INVOICE_CREATED, $result->outcome);
        $this->assertTrue($result->allowsCaseActivation);
        $this->assertNotNull($result->invoice);
        $this->assertSame(InvoiceType::CASE_EXTRA, $result->invoice->getType());
        $this->assertSame('25.00', $result->invoice->getAmount());
        $this->assertSame($this->user->getId(), $result->invoice->getUser()->getId());
        $this->assertSame(6, $sub->getCasesConsumed());
    }

    public function testActiveExhaustedPersistsDurableSlotOverageNotificationRow(): void
    {
        $plan = $this->createPlan(includedCases: 5, pricePerExtra: '25.00');
        $this->createSubscription($plan, SubscriptionStatus::ACTIVE, casesConsumed: 5);

        $result = $this->service->consumeCaseSlot($this->createCase());
        $invoiceId = $result->invoice->getId();

        $this->em->clear();

        $notification = $this->em->getRepository(Notification::class)
            ->findOneBy(['dedupKey' => 'slot_overage:' . $invoiceId]);

        self::assertNotNull($notification, 'a durable in-app notification row must land for a billed overage');
        self::assertSame(NotificationType::SLOT_OVERAGE->value, $notification->getType());
        self::assertSame($this->user->getId(), $notification->getUser()->getId());
        self::assertNull($notification->getLegalCase());
    }

    public function testSlotOverageDedupKeyDoesNotDoublePersist(): void
    {
        $plan = $this->createPlan(includedCases: 5, pricePerExtra: '25.00');
        $this->createSubscription($plan, SubscriptionStatus::ACTIVE, casesConsumed: 5);

        // First overage persists exactly one durable row keyed on the invoice id.
        $result = $this->service->consumeCaseSlot($this->createCase());
        $dedupKey = 'slot_overage:' . $result->invoice->getId();

        // Re-dispatch the identical event (same dedupKey) through the real dispatcher:
        // the dedup guard must no-op, leaving a single row.
        $dispatcher = static::getContainer()->get(NotificationDispatcherInterface::class);
        $dispatcher->dispatch(new NotificationDispatch(
            user: $this->user,
            legalCase: null,
            type: NotificationType::SLOT_OVERAGE,
            title: 'x',
            message: 'y',
            resourceLink: '/invoices',
            variant: 'warning',
            emailSubject: null,
            emailTemplate: null,
            dedupKey: $dedupKey,
        ));

        $this->em->clear();

        $rows = $this->em->getRepository(Notification::class)->findBy(['dedupKey' => $dedupKey]);
        self::assertCount(1, $rows, 'a second dispatch with the same dedupKey must not double-persist');
    }

    public function testTrialConsumedWhenFreeSlot(): void
    {
        $plan = $this->createPlan(includedCases: 2, isTrial: true, priceMonthly: '0.00', pricePerExtra: '0.00');
        $sub = $this->createSubscription($plan, SubscriptionStatus::TRIAL, casesConsumed: 0);

        $result = $this->service->consumeCaseSlot($this->createCase());

        $this->assertSame(SubscriptionSlotConsumptionOutcome::TRIAL_CONSUMED, $result->outcome);
        $this->assertTrue($result->allowsCaseActivation);
        $this->assertNull($result->invoice);
        $this->assertSame(1, $sub->getCasesConsumed());
    }

    public function testTrialExhaustedDoesNotInvoiceOrIncrement(): void
    {
        $plan = $this->createPlan(includedCases: 2, isTrial: true, priceMonthly: '0.00', pricePerExtra: '0.00');
        $sub = $this->createSubscription($plan, SubscriptionStatus::TRIAL, casesConsumed: 2);

        $result = $this->service->consumeCaseSlot($this->createCase());

        $this->assertSame(SubscriptionSlotConsumptionOutcome::TRIAL_EXHAUSTED, $result->outcome);
        $this->assertFalse($result->allowsCaseActivation);
        $this->assertNull($result->invoice);
        $this->assertSame(2, $sub->getCasesConsumed());
    }

    public function testNoSubscriptionReturnsNoActiveSubscription(): void
    {
        $result = $this->service->consumeCaseSlot($this->createCase());

        $this->assertSame(SubscriptionSlotConsumptionOutcome::NO_ACTIVE_SUBSCRIPTION, $result->outcome);
        $this->assertFalse($result->allowsCaseActivation);
        $this->assertNull($result->subscription);
    }

    public function testExpiredSubscriptionIsNotCurrent(): void
    {
        $plan = $this->createPlan(includedCases: 5);
        $this->createSubscription($plan, SubscriptionStatus::ACTIVE, casesConsumed: 0, periodEndModifier: '-1 day');

        $result = $this->service->consumeCaseSlot($this->createCase());

        $this->assertSame(SubscriptionSlotConsumptionOutcome::NO_ACTIVE_SUBSCRIPTION, $result->outcome);
    }

    public function testPastDueSubscriptionIsBlocked(): void
    {
        $plan = $this->createPlan(includedCases: 5);
        $this->createSubscription($plan, SubscriptionStatus::PAST_DUE, casesConsumed: 0);

        $result = $this->service->consumeCaseSlot($this->createCase());

        $this->assertSame(SubscriptionSlotConsumptionOutcome::NO_ACTIVE_SUBSCRIPTION, $result->outcome);
        $this->assertFalse($result->allowsCaseActivation);
    }

    public function testSuspendedSubscriptionIsBlocked(): void
    {
        $plan = $this->createPlan(includedCases: 5);
        $this->createSubscription($plan, SubscriptionStatus::SUSPENDED, casesConsumed: 0);

        $result = $this->service->consumeCaseSlot($this->createCase());

        $this->assertSame(SubscriptionSlotConsumptionOutcome::NO_ACTIVE_SUBSCRIPTION, $result->outcome);
        $this->assertFalse($result->allowsCaseActivation);
    }

    public function testCanceledWithinPeriodStillConsumes(): void
    {
        $plan = $this->createPlan(includedCases: 5);
        $sub = $this->createSubscription($plan, SubscriptionStatus::CANCELED, casesConsumed: 1);

        $result = $this->service->consumeCaseSlot($this->createCase());

        $this->assertSame(SubscriptionSlotConsumptionOutcome::CONSUMED_FROM_PLAN, $result->outcome);
        $this->assertSame(2, $sub->getCasesConsumed());
    }

    public function testStartTrialCreatesTrialSubscription(): void
    {
        // Ensure a trial plan exists for the service to find.
        $this->createPlan(includedCases: 2, isTrial: true, priceMonthly: '0.00', pricePerExtra: '0.00');
        $this->em->flush();

        $sub = $this->service->startTrial($this->user);

        $this->assertSame(SubscriptionStatus::TRIAL, $sub->getStatus());
        $this->assertTrue($sub->getPlan()->isTrial());
        $this->assertSame(0, $sub->getCasesConsumed());
        $this->assertGreaterThan($sub->getCurrentPeriodStart(), $sub->getCurrentPeriodEnd());
    }

    public function testSubscribeToPlanCreatesActiveSubscriptionAndInvoice(): void
    {
        $plan = $this->createPlan(includedCases: 5, priceMonthly: '99.00');
        $this->em->flush();

        $sub = $this->service->subscribeToPlan($this->user, $plan);

        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->getStatus());
        $this->assertSame($plan->getId(), $sub->getPlan()->getId());
        $this->assertSame(0, $sub->getCasesConsumed());

        $invoices = $this->em->getRepository(\App\Entity\Invoice::class)->findByUser($this->user);
        $this->assertCount(1, $invoices);
        $this->assertSame(InvoiceType::SUBSCRIPTION, $invoices[0]->getType());
        $this->assertSame('99.00', $invoices[0]->getAmount());
    }

    public function testSubscribeToPlanConvertsTrial(): void
    {
        $trialPlan = $this->createPlan(includedCases: 2, isTrial: true, priceMonthly: '0.00', pricePerExtra: '0.00');
        $trial = $this->createSubscription($trialPlan, SubscriptionStatus::TRIAL, casesConsumed: 1);
        $paidPlan = $this->createPlan(includedCases: 5, priceMonthly: '99.00');
        $this->em->flush();

        $sub = $this->service->subscribeToPlan($this->user, $paidPlan);

        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->getStatus());
        $this->assertSame(SubscriptionStatus::CANCELED, $trial->getStatus(), 'Trial must be canceled on conversion.');
    }

    public function testSubscribeToPlanRejectsWhenAlreadyPaid(): void
    {
        $current = $this->createPlan(includedCases: 5, priceMonthly: '99.00');
        $this->createSubscription($current, SubscriptionStatus::ACTIVE, casesConsumed: 0);
        $other = $this->createPlan(includedCases: 25, priceMonthly: '299.00');
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->service->subscribeToPlan($this->user, $other);
    }

    public function testCancelSubscriptionSetsCanceledStatus(): void
    {
        $plan = $this->createPlan(includedCases: 5);
        $sub = $this->createSubscription($plan, SubscriptionStatus::ACTIVE, casesConsumed: 0);

        $this->service->cancelSubscription($sub);

        $this->assertSame(SubscriptionStatus::CANCELED, $sub->getStatus());
    }

    public function testRenewSubscriptionIssuesInvoiceResetsAndExtends(): void
    {
        $plan = $this->createPlan(includedCases: 5, priceMonthly: '99.00');
        $sub = $this->createSubscription($plan, SubscriptionStatus::ACTIVE, casesConsumed: 4);
        $oldEnd = $sub->getCurrentPeriodEnd();

        $this->service->renewSubscription($sub);

        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->getStatus());
        $this->assertSame(0, $sub->getCasesConsumed());
        $this->assertSame($oldEnd->getTimestamp(), $sub->getCurrentPeriodStart()->getTimestamp());
        $this->assertGreaterThan($oldEnd, $sub->getCurrentPeriodEnd());

        $invoices = $this->em->getRepository(\App\Entity\Invoice::class)->findByUser($this->user);
        $this->assertCount(1, $invoices);
        $this->assertSame(InvoiceType::SUBSCRIPTION, $invoices[0]->getType());
        $this->assertSame('99.00', $invoices[0]->getAmount());
    }

    public function testRecommendUpgradeReturnsBiggerPlan(): void
    {
        $current = $this->createPlan(includedCases: 1, priceMonthly: '10.00');
        $this->createPlan(includedCases: 10, priceMonthly: '50.00'); // explicit upgrade target
        $sub = $this->createSubscription($current, SubscriptionStatus::ACTIVE, casesConsumed: 0);

        $upgrade = $this->service->recommendUpgrade($sub);

        $this->assertNotNull($upgrade);
        $this->assertGreaterThan(1, $upgrade->getIncludedCases());
    }

    public function testRecommendUpgradeReturnsNullAtTop(): void
    {
        $current = $this->createPlan(includedCases: 99999);
        $sub = $this->createSubscription($current, SubscriptionStatus::ACTIVE, casesConsumed: 0);

        $this->assertNull($this->service->recommendUpgrade($sub));
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE i FROM invoice i JOIN user u ON i.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE s FROM subscription s JOIN user u ON s.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM plan WHERE name LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
