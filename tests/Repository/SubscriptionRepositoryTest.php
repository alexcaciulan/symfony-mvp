<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Invoice;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Enum\SubscriptionStatus;
use App\Repository\SubscriptionRepository;
use App\Service\Billing\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SubscriptionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SubscriptionRepository $repository;
    private SubscriptionService $service;
    private User $user;
    private Plan $plan;
    private string $prefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(SubscriptionRepository::class);
        $this->service = static::getContainer()->get(SubscriptionService::class);
        $this->prefix = 'sub-repo-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->prefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $this->plan = new Plan();
        $this->plan->setName($this->prefix . '-plan');
        $this->plan->setPriceMonthly('99.00');
        $this->plan->setIncludedCases(5);
        $this->plan->setPricePerExtra('25.00');
        $this->plan->setIsActive(true);
        $this->plan->setIsTrial(false);
        $this->em->persist($this->plan);
        $this->em->flush();
    }

    private function createSubscription(SubscriptionStatus $status, string $periodEnd, string $createdAt = 'now'): Subscription
    {
        $sub = new Subscription();
        $sub->setUser($this->user);
        $sub->setPlan($this->plan);
        $sub->setStatus($status);
        $sub->setCurrentPeriodStart(new \DateTimeImmutable('-1 day'));
        $sub->setCurrentPeriodEnd(new \DateTimeImmutable($periodEnd));
        $sub->setCasesConsumed(0);
        $this->em->persist($sub);
        $this->em->flush();

        if ('now' !== $createdAt) {
            // created_at is set in the constructor; rewrite it so age-based
            // queries can be exercised without waiting.
            $this->em->getConnection()->executeStatement(
                'UPDATE subscription SET created_at = ? WHERE id = ?',
                [(new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'), $sub->getId()],
            );
            $this->em->refresh($sub);
        }

        return $sub;
    }

    private function createInvoice(Subscription $sub, InvoiceStatus $status, InvoiceType $type = InvoiceType::SUBSCRIPTION): Invoice
    {
        $invoice = new Invoice();
        $invoice->setUser($this->user);
        $invoice->setSubscription($sub);
        $invoice->setType($type);
        $invoice->setStatus($status);
        $invoice->setAmount('99.00');
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    public function testFindCurrentForUserIsDeterministicWithOverlappingSubscriptions(): void
    {
        $older = $this->createSubscription(SubscriptionStatus::CANCELED, '+30 days');
        $newer = $this->createSubscription(SubscriptionStatus::ACTIVE, '+30 days');

        // Identical period ends: without an id tie-break the winner is up to the
        // storage engine, which would split slot consumption from billing.
        self::assertSame($newer->getId(), $this->repository->findCurrentForUser($this->user)->getId());
        self::assertNotSame($older->getId(), $this->repository->findCurrentForUser($this->user)->getId());
    }

    public function testFindActiveForUserIsDeterministicWithOverlappingSubscriptions(): void
    {
        $this->createSubscription(SubscriptionStatus::ACTIVE, '+30 days');
        $newer = $this->createSubscription(SubscriptionStatus::ACTIVE, '+30 days');

        self::assertSame($newer->getId(), $this->repository->findActiveForUser($this->user)->getId());
    }

    public function testAbandonedCheckoutIsFoundOnceOldEnough(): void
    {
        $sub = $this->createSubscription(SubscriptionStatus::ACTIVE, '+30 days', '-10 days');
        $this->createInvoice($sub, InvoiceStatus::PENDING);

        $found = $this->repository->findAbandonedCheckoutsCreatedBefore(new \DateTimeImmutable('-3 days'));

        self::assertSame([$sub->getId()], array_map(static fn ($s) => $s->getId(), $found));
    }

    public function testSubscriptionWithoutAnyInvoiceIsNotTreatedAsAbandoned(): void
    {
        $this->createSubscription(SubscriptionStatus::ACTIVE, '+30 days', '-10 days');

        // Seeded or hand-created subscriptions have no invoices at all; releasing
        // them would wipe demo and fixture data.
        self::assertSame([], $this->repository->findAbandonedCheckoutsCreatedBefore(new \DateTimeImmutable('-3 days')));
    }

    public function testPaidSubscriptionIsNotTreatedAsAbandoned(): void
    {
        $sub = $this->createSubscription(SubscriptionStatus::ACTIVE, '+30 days', '-10 days');
        $this->createInvoice($sub, InvoiceStatus::PAID);
        // A later unpaid invoice is a failed renewal, which belongs to dunning.
        $this->createInvoice($sub, InvoiceStatus::PENDING);

        self::assertSame([], $this->repository->findAbandonedCheckoutsCreatedBefore(new \DateTimeImmutable('-3 days')));
    }

    public function testRecentAbandonedCheckoutIsSpared(): void
    {
        $sub = $this->createSubscription(SubscriptionStatus::ACTIVE, '+30 days');
        $this->createInvoice($sub, InvoiceStatus::PENDING);

        self::assertSame([], $this->repository->findAbandonedCheckoutsCreatedBefore(new \DateTimeImmutable('-3 days')));
    }

    public function testExpireAbandonedCheckoutsReleasesTheSlotAndCancelsInvoices(): void
    {
        $sub = $this->createSubscription(SubscriptionStatus::ACTIVE, '+30 days', '-10 days');
        $invoice = $this->createInvoice($sub, InvoiceStatus::PENDING);

        $released = $this->service->expireAbandonedCheckouts(new \DateTimeImmutable('-3 days'));

        self::assertSame(1, $released);
        self::assertSame(SubscriptionStatus::PAST_DUE, $sub->getStatus());
        self::assertSame(InvoiceStatus::CANCELED, $invoice->getStatus());
        // PAST_DUE is excluded from findCurrentForUser, so the user can subscribe again.
        self::assertNull($this->repository->findCurrentForUser($this->user));
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE entity_type = ? AND entity_id IN (SELECT s.id FROM subscription s JOIN user u ON s.user_id = u.id WHERE u.email LIKE ?)', ['Subscription', $this->prefix . '%']);
        $conn->executeStatement('DELETE i FROM invoice i JOIN user u ON i.user_id = u.id WHERE u.email LIKE ?', [$this->prefix . '%']);
        $conn->executeStatement('DELETE s FROM subscription s JOIN user u ON s.user_id = u.id WHERE u.email LIKE ?', [$this->prefix . '%']);
        $conn->executeStatement('DELETE FROM plan WHERE name LIKE ?', [$this->prefix . '%']);
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->prefix . '%']);
        parent::tearDown();
    }
}
