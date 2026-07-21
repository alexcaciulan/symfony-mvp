<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Enum\PlanChangeOutcome;
use App\Enum\SubscriptionStatus;
use App\Repository\InvoiceRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Billing\InvoicingService;
use App\Service\Billing\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SubscriptionServiceChangePlanTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SubscriptionService $service;
    private InvoicingService $invoicing;
    private InvoiceRepository $invoices;
    private User $user;
    private string $testPrefix;
    private int $planCounter = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(SubscriptionService::class);
        $this->invoicing = static::getContainer()->get(InvoicingService::class);
        $this->invoices = static::getContainer()->get(InvoiceRepository::class);
        $this->testPrefix = 'change-plan-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createPlan(string $priceMonthly, int $includedCases, bool $isTrial = false, bool $isActive = true): Plan
    {
        $plan = new Plan();
        $plan->setName($this->testPrefix . '-plan-' . (++$this->planCounter));
        $plan->setPriceMonthly($priceMonthly);
        $plan->setIncludedCases($includedCases);
        $plan->setPricePerExtra('25.00');
        $plan->setIsActive($isActive);
        $plan->setIsTrial($isTrial);
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    private function createSubscription(Plan $plan, int $casesConsumed = 0): Subscription
    {
        $now = new \DateTimeImmutable();
        $sub = new Subscription();
        $sub->setUser($this->user);
        $sub->setPlan($plan);
        $sub->setStatus(SubscriptionStatus::ACTIVE);
        $sub->setCasesConsumed($casesConsumed);
        $sub->setCurrentPeriodStart($now->modify('-15 days'));
        $sub->setCurrentPeriodEnd($now->modify('+15 days'));
        $this->em->persist($sub);
        $this->em->flush();

        return $sub;
    }

    public function testUpgradeDoesNotChangePlanBeforePayment(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter, 5);
        $periodEnd = $sub->getCurrentPeriodEnd();

        $result = $this->service->changePlan($sub, $pro);

        $this->assertSame(PlanChangeOutcome::UPGRADE_PENDING_PAYMENT, $result->outcome);
        $this->assertSame($starter, $sub->getPlan());
        $this->assertSame(5, $sub->getCasesConsumed());
        $this->assertEquals($periodEnd, $sub->getCurrentPeriodEnd());
    }

    public function testUpgradeIssuesFullPricePlanChangeInvoice(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter);

        $result = $this->service->changePlan($sub, $pro);

        $this->assertNotNull($result->invoice);
        // No proration by product decision: the full price of the new plan.
        $this->assertSame('299.00', $result->invoice->getAmount());
        $this->assertSame(InvoiceType::PLAN_CHANGE, $result->invoice->getType());
        $this->assertSame(InvoiceStatus::PENDING, $result->invoice->getStatus());
        $this->assertSame($pro, $result->invoice->getTargetPlan());
        $this->assertSame($sub, $result->invoice->getSubscription());
    }

    public function testUpgradeAppliesPlanOnceInvoiceIsPaid(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter, 5);

        $result = $this->service->changePlan($sub, $pro);
        $this->invoicing->markPaid($result->invoice, 'ntp-123', 'test');

        $this->assertSame($pro, $sub->getPlan());
        $this->assertSame(0, $sub->getCasesConsumed());
        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->getStatus());
        $this->assertNotNull($sub->getPlanChangedAt());
    }

    public function testUpgradeKeepsRecurringTokenAndCardMask(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter);
        $sub->setRecurringToken('tok-keep-me')->setCardMask('4111 **** 1111');
        $this->em->flush();

        $result = $this->service->changePlan($sub, $pro);
        $this->invoicing->markPaid($result->invoice, 'ntp-124', 'test');

        // The token is not tied to an amount, so a plan change needs nothing
        // from the gateway. Losing it here would silently break renewals.
        $this->assertSame('tok-keep-me', $sub->getRecurringToken());
        $this->assertSame('4111 **** 1111', $sub->getCardMask());
    }

    public function testUpgradeDoesNotCreateSecondSubscriptionRow(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter);

        $result = $this->service->changePlan($sub, $pro);
        $this->invoicing->markPaid($result->invoice, 'ntp-125', 'test');

        $rows = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM subscription WHERE user_id = ?',
            [$this->user->getId()],
        );
        // Two ACTIVE rows would mean two recurring charges per month.
        $this->assertSame(1, $rows);
    }

    public function testSecondUpgradeRequestCancelsThePreviousPendingInvoice(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $mid = $this->createPlan('199.00', 15);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter);

        $first = $this->service->changePlan($sub, $mid)->invoice;
        $second = $this->service->changePlan($sub, $pro)->invoice;

        $this->assertSame(InvoiceStatus::CANCELED, $first->getStatus());
        $this->assertSame(InvoiceStatus::PENDING, $second->getStatus());
        $this->assertCount(1, $this->invoices->findPendingByType($sub, InvoiceType::PLAN_CHANGE));
    }

    public function testDowngradeSchedulesPendingPlanWithoutInvoice(): void
    {
        $pro = $this->createPlan('299.00', 25);
        $starter = $this->createPlan('99.00', 5);
        $sub = $this->createSubscription($pro, 7);

        $result = $this->service->changePlan($sub, $starter);

        $this->assertSame(PlanChangeOutcome::DOWNGRADE_SCHEDULED, $result->outcome);
        $this->assertNull($result->invoice);
        $this->assertSame($starter, $result->scheduledPlan);
        $this->assertSame($starter, $sub->getPendingPlan());
        // Applying it now would owe a refund and strand 7 consumed cases
        // above the cheaper plan's 5 included ones.
        $this->assertSame($pro, $sub->getPlan());
        $this->assertSame(7, $sub->getCasesConsumed());
    }

    public function testRenewalAppliesPendingPlanAndInvoicesNewPrice(): void
    {
        $pro = $this->createPlan('299.00', 25);
        $starter = $this->createPlan('99.00', 5);
        $sub = $this->createSubscription($pro, 7);

        $this->service->changePlan($sub, $starter);
        $invoice = $this->service->renewSubscription($sub);

        $this->assertSame($starter, $sub->getPlan());
        $this->assertNull($sub->getPendingPlan());
        $this->assertSame('99.00', $invoice->getAmount());
        $this->assertSame(InvoiceType::SUBSCRIPTION, $invoice->getType());
        $this->assertSame(0, $sub->getCasesConsumed());
    }

    public function testRenewalChargesNewPlanPriceAfterUpgrade(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter);

        $upgrade = $this->service->changePlan($sub, $pro);
        $this->invoicing->markPaid($upgrade->invoice, 'ntp-127', 'test');
        $renewal = $this->service->renewSubscription($sub);

        // The recurring amount is read off the plan at renewal time, which is why
        // the Netopia token needs no re-authorization after a plan change.
        $this->assertSame('299.00', $renewal->getAmount());
        $this->assertSame(InvoiceType::SUBSCRIPTION, $renewal->getType());
    }

    public function testFindDueForRenewalReturnsAtMostOneRowPerUserAfterPlanChange(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter);
        $sub->setRecurringToken('tok-renew');
        $this->em->flush();

        $upgrade = $this->service->changePlan($sub, $pro);
        $this->invoicing->markPaid($upgrade->invoice, 'ntp-128', 'test');

        // Force the (restarted) period to be due.
        $sub->setCurrentPeriodEnd(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $due = array_filter(
            static::getContainer()->get(SubscriptionRepository::class)->findDueForRenewal(new \DateTimeImmutable()),
            fn ($s) => $s->getUser()->getId() === $this->user->getId(),
        );

        // Two due rows for one user would mean two off-session charges per month,
        // which is exactly what creating a second subscription would have caused.
        $this->assertCount(1, $due);
    }

    public function testRenewalSupersedesAnUnpaidUpgradeInvoice(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter);

        // User asks for the upgrade but abandons checkout, then the period ends
        // and the renewal cron runs before the payment ever lands.
        $upgrade = $this->service->changePlan($sub, $pro)->invoice;
        $renewal = $this->service->renewSubscription($sub);

        // Left PENDING, a later settlement would charge 299 on top of this 99 and
        // overwrite the period and counter the renewal just set.
        $this->assertSame(InvoiceStatus::CANCELED, $upgrade->getStatus());
        $this->assertSame('99.00', $renewal->getAmount());
        $this->assertSame($starter, $sub->getPlan());
        $this->assertCount(0, $this->invoices->findPendingByType($sub, InvoiceType::PLAN_CHANGE));
    }

    public function testSettlingASupersededUpgradeDoesNotChangeThePlan(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter);

        $upgrade = $this->service->changePlan($sub, $pro)->invoice;
        $this->service->renewSubscription($sub);
        $periodEnd = $sub->getCurrentPeriodEnd();

        // A late IPN on the superseded invoice must be inert: markPaid only
        // applies plan changes to invoices still PENDING.
        $this->invoicing->markPaid($upgrade, 'ntp-late', 'test');

        $this->assertSame($starter, $sub->getPlan());
        $this->assertEquals($periodEnd, $sub->getCurrentPeriodEnd());
    }

    public function testCancelScheduledPlanChangeDropsThePendingPlan(): void
    {
        $pro = $this->createPlan('299.00', 25);
        $starter = $this->createPlan('99.00', 5);
        $sub = $this->createSubscription($pro);

        $this->service->changePlan($sub, $starter);
        $this->service->cancelScheduledPlanChange($sub);

        $this->assertNull($sub->getPendingPlan());
        $this->assertSame($pro, $sub->getPlan());
    }

    public function testPaidUpgradeClearsScheduledDowngrade(): void
    {
        $mid = $this->createPlan('199.00', 15);
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($mid);

        $this->service->changePlan($sub, $starter);
        $upgrade = $this->service->changePlan($sub, $pro);
        $this->invoicing->markPaid($upgrade->invoice, 'ntp-126', 'test');

        $this->assertSame($pro, $sub->getPlan());
        $this->assertNull($sub->getPendingPlan());
    }

    public function testEqualPriceChangeIsScheduledWithoutInvoice(): void
    {
        $current = $this->createPlan('149.00', 10);
        $sibling = $this->createPlan('149.00', 12);
        $sub = $this->createSubscription($current);

        $result = $this->service->changePlan($sub, $sibling);

        $this->assertSame(PlanChangeOutcome::CHANGE_SCHEDULED, $result->outcome);
        $this->assertNull($result->invoice);
        $this->assertSame($sibling, $sub->getPendingPlan());
    }

    public function testChangePlanRejectsTrialSubscription(): void
    {
        $trial = $this->createPlan('0.00', 1, isTrial: true);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($trial);

        $this->expectException(\DomainException::class);
        $this->service->changePlan($sub, $pro);
    }

    public function testChangePlanRejectsInactiveTargetPlan(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $retired = $this->createPlan('299.00', 25, isActive: false);
        $sub = $this->createSubscription($starter);

        $this->expectException(\DomainException::class);
        $this->service->changePlan($sub, $retired);
    }

    public function testChangePlanRejectsTrialTargetPlan(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $trial = $this->createPlan('0.00', 1, isTrial: true);
        $sub = $this->createSubscription($starter);

        $this->expectException(\DomainException::class);
        $this->service->changePlan($sub, $trial);
    }

    public function testChangePlanRejectsTheCurrentPlan(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $sub = $this->createSubscription($starter);

        $this->expectException(\DomainException::class);
        $this->service->changePlan($sub, $starter);
    }

    public function testChangePlanWritesAuditLog(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter);

        $this->service->changePlan($sub, $pro);

        $actions = $this->em->getConnection()->fetchFirstColumn(
            'SELECT action FROM audit_log WHERE entity_type = ? AND entity_id = ?',
            ['Subscription', (string) $sub->getId()],
        );
        $this->assertContains('subscription_plan_change_requested', $actions);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE entity_type = ? AND entity_id IN (SELECT s.id FROM subscription s JOIN user u ON s.user_id = u.id WHERE u.email LIKE ?)', ['Subscription', $this->testPrefix . '%']);
        $conn->executeStatement('DELETE i FROM invoice i JOIN user u ON i.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE s FROM subscription s JOIN user u ON s.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM plan WHERE name LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
