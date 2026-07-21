<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\Invoice;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Enum\SubscriptionStatus;
use App\Service\Billing\PlanChangeApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PlanChangeApplierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PlanChangeApplier $applier;
    private User $user;
    private string $testPrefix;
    private int $planCounter = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->applier = static::getContainer()->get(PlanChangeApplier::class);
        $this->testPrefix = 'plan-change-applier-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createPlan(string $priceMonthly, int $includedCases): Plan
    {
        $plan = new Plan();
        $plan->setName($this->testPrefix . '-plan-' . (++$this->planCounter));
        $plan->setPriceMonthly($priceMonthly);
        $plan->setIncludedCases($includedCases);
        $plan->setPricePerExtra('25.00');
        $plan->setIsActive(true);
        $plan->setIsTrial(false);
        $this->em->persist($plan);

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

    private function createPlanChangeInvoice(Subscription $sub, ?Plan $targetPlan, InvoiceType $type = InvoiceType::PLAN_CHANGE): Invoice
    {
        $invoice = new Invoice();
        $invoice->setUser($this->user);
        $invoice->setSubscription($sub);
        $invoice->setTargetPlan($targetPlan);
        $invoice->setType($type);
        $invoice->setStatus(InvoiceStatus::PENDING);
        $invoice->setAmount(null !== $targetPlan ? $targetPlan->getPriceMonthly() : '99.00');
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    public function testAppliesTargetPlanToSubscription(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter);

        $this->applier->applyPaidPlanChange($this->createPlanChangeInvoice($sub, $pro));

        $this->assertSame($pro, $sub->getPlan());
        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->getStatus());
        $this->assertNotNull($sub->getPlanChangedAt());
    }

    public function testRestartsBillingPeriodFromNow(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter);
        $oldEnd = $sub->getCurrentPeriodEnd();

        $this->applier->applyPaidPlanChange($this->createPlanChangeInvoice($sub, $pro));

        // No proration: the user paid a full period, so it starts over today.
        $this->assertGreaterThan($oldEnd, $sub->getCurrentPeriodEnd());
        $this->assertSame(
            $sub->getCurrentPeriodStart()->modify('+1 month')->format('Y-m-d'),
            $sub->getCurrentPeriodEnd()->format('Y-m-d'),
        );
        $this->assertSame((new \DateTimeImmutable())->format('Y-m-d'), $sub->getCurrentPeriodStart()->format('Y-m-d'));
    }

    public function testResetsCasesConsumedToZero(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter, 5);

        $this->applier->applyPaidPlanChange($this->createPlanChangeInvoice($sub, $pro));

        $this->assertSame(0, $sub->getCasesConsumed());
    }

    public function testClearsPendingPlan(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $cheap = $this->createPlan('49.00', 2);
        $sub = $this->createSubscription($starter);
        $sub->setPendingPlan($cheap);
        $this->em->flush();

        $this->applier->applyPaidPlanChange($this->createPlanChangeInvoice($sub, $pro));

        // A paid upgrade supersedes a downgrade that was scheduled for the renewal.
        $this->assertNull($sub->getPendingPlan());
        $this->assertSame($pro, $sub->getPlan());
    }

    public function testIgnoresInvoicesWithoutTargetPlan(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $sub = $this->createSubscription($starter, 3);

        $this->applier->applyPaidPlanChange($this->createPlanChangeInvoice($sub, null));

        $this->assertSame($starter, $sub->getPlan());
        $this->assertSame(3, $sub->getCasesConsumed());
        $this->assertNull($sub->getPlanChangedAt());
    }

    public function testIgnoresNonPlanChangeInvoiceTypes(): void
    {
        $starter = $this->createPlan('99.00', 5);
        $pro = $this->createPlan('299.00', 25);
        $sub = $this->createSubscription($starter, 3);

        $this->applier->applyPaidPlanChange($this->createPlanChangeInvoice($sub, $pro, InvoiceType::SUBSCRIPTION));

        $this->assertSame($starter, $sub->getPlan());
        $this->assertSame(3, $sub->getCasesConsumed());
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE i FROM invoice i JOIN user u ON i.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE s FROM subscription s JOIN user u ON s.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM plan WHERE name LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
