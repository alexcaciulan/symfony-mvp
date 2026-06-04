<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\LegalCase;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Enum\SubscriptionStatus;
use App\Service\Billing\InvoicingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class InvoicingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InvoicingService $service;
    private User $user;
    private Subscription $subscription;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(InvoicingService::class);
        $this->testPrefix = 'billing-inv-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $plan = new Plan();
        $plan->setName($this->testPrefix . '-plan');
        $plan->setPriceMonthly('99.00');
        $plan->setIncludedCases(5);
        $plan->setPricePerExtra('25.00');
        $plan->setIsActive(true);
        $this->em->persist($plan);

        $now = new \DateTimeImmutable();
        $this->subscription = new Subscription();
        $this->subscription->setUser($this->user);
        $this->subscription->setPlan($plan);
        $this->subscription->setStatus(SubscriptionStatus::ACTIVE);
        $this->subscription->setCurrentPeriodStart($now);
        $this->subscription->setCurrentPeriodEnd($now->modify('+30 days'));
        $this->em->persist($this->subscription);
        $this->em->flush();
    }

    public function testCreateSubscriptionInvoice(): void
    {
        $invoice = $this->service->createSubscriptionInvoice($this->subscription);

        $this->assertNotNull($invoice->getId());
        $this->assertSame(InvoiceType::SUBSCRIPTION, $invoice->getType());
        $this->assertSame(InvoiceStatus::PENDING, $invoice->getStatus());
        $this->assertSame('99.00', $invoice->getAmount());
        $this->assertSame($this->user->getId(), $invoice->getUser()->getId());
        $this->assertNull($invoice->getLegalCase());
    }

    public function testCreateCaseExtraInvoice(): void
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus(CaseStatus::AMIABIL);
        $this->em->persist($case);
        $this->em->flush();

        $invoice = $this->service->createCaseExtraInvoice($this->subscription, $case);

        $this->assertSame(InvoiceType::CASE_EXTRA, $invoice->getType());
        $this->assertSame('25.00', $invoice->getAmount());
        $this->assertSame($case->getId(), $invoice->getLegalCase()->getId());
    }

    public function testMarkPaidSetsStatusPaidAtAndExternalRef(): void
    {
        $invoice = $this->service->createSubscriptionInvoice($this->subscription);

        $this->service->markPaid($invoice, 'ext_ref_42');

        $this->assertSame(InvoiceStatus::PAID, $invoice->getStatus());
        $this->assertInstanceOf(\DateTimeImmutable::class, $invoice->getPaidAt());
        $this->assertSame('ext_ref_42', $invoice->getExternalId());
    }

    public function testGetInvoicesByUserFiltersByStatusAndType(): void
    {
        $paid = $this->service->createSubscriptionInvoice($this->subscription);
        $this->service->markPaid($paid);
        $this->service->createSubscriptionInvoice($this->subscription); // pending

        $all = $this->service->getInvoicesByUser($this->user);
        $this->assertCount(2, $all);

        $pendingOnly = $this->service->getInvoicesByUser($this->user, ['status' => InvoiceStatus::PENDING]);
        $this->assertCount(1, $pendingOnly);
        $this->assertSame(InvoiceStatus::PENDING, $pendingOnly[0]->getStatus());

        $subscriptionType = $this->service->getInvoicesByUser($this->user, ['type' => InvoiceType::SUBSCRIPTION]);
        $this->assertCount(2, $subscriptionType);

        $caseExtraType = $this->service->getInvoicesByUser($this->user, ['type' => InvoiceType::CASE_EXTRA]);
        $this->assertCount(0, $caseExtraType);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE i FROM invoice i JOIN user u ON i.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE s FROM subscription s JOIN user u ON s.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM plan WHERE name LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
