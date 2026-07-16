<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\Invoice;
use App\Entity\Notification;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\NotificationType;
use App\Enum\SubscriptionStatus;
use App\Service\Billing\InvoicingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration coverage for the atomic settlement path: markPaid transitions
 * PENDING → PAID once (idempotent under replay) and its transactional flush
 * persists the recurring token staged on the subscription in the same unit of
 * work (as the webhook handler relies on).
 */
class InvoicingServiceMarkPaidTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InvoicingService $invoicing;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->invoicing = static::getContainer()->get(InvoicingService::class);
        $this->testPrefix = 'billing-markpaid-' . uniqid();

        $this->user = (new User())
            ->setEmail($this->testPrefix . '@test.com')
            ->setPassword('x')
            ->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function createSubscriptionWithPendingInvoice(): array
    {
        $plan = (new Plan())
            ->setName($this->testPrefix . '-plan')
            ->setPriceMonthly('99.00')
            ->setIncludedCases(5)
            ->setPricePerExtra('25.00')
            ->setIsActive(true)
            ->setIsTrial(false);
        $this->em->persist($plan);

        $now = new \DateTimeImmutable();
        $sub = (new Subscription())
            ->setUser($this->user)
            ->setPlan($plan)
            ->setStatus(SubscriptionStatus::ACTIVE)
            ->setCurrentPeriodStart($now)
            ->setCurrentPeriodEnd($now->modify('+1 month'));
        $this->em->persist($sub);
        $this->em->flush();

        $invoice = $this->invoicing->createSubscriptionInvoice($sub);

        return [$sub, $invoice];
    }

    public function testMarkPaidSettlesAndPersistsTokenInOneFlush(): void
    {
        [$sub, $invoice] = $this->createSubscriptionWithPendingInvoice();
        $invoiceId = $invoice->getId();
        $subId = $sub->getId();

        // Stage the token on the managed subscription, as the webhook handler does.
        $sub->setRecurringToken('save-tok')->setCardMask('4111 **** 1111');

        $this->invoicing->markPaid($invoice, 'NTP-1', 'webhook');

        $this->em->clear();

        $reloadedInvoice = $this->em->find(Invoice::class, $invoiceId);
        self::assertSame(InvoiceStatus::PAID, $reloadedInvoice->getStatus());
        self::assertSame('NTP-1', $reloadedInvoice->getExternalId());

        $reloadedSub = $this->em->find(Subscription::class, $subId);
        self::assertSame('save-tok', $reloadedSub->getRecurringToken());
        self::assertSame('4111 **** 1111', $reloadedSub->getCardMask());
    }

    public function testMarkPaidIsIdempotentOnReplay(): void
    {
        [, $invoice] = $this->createSubscriptionWithPendingInvoice();
        $invoiceId = $invoice->getId();

        $this->invoicing->markPaid($invoice, 'NTP-1', 'webhook');
        $this->em->clear();
        $paidAt = $this->em->find(Invoice::class, $invoiceId)->getPaidAt()->format('Y-m-d H:i:s');

        // Replay: a duplicate IPN must not re-settle or throw.
        $this->invoicing->markPaid($invoice, 'NTP-2', 'reconciliation');

        $this->em->clear();
        $reloaded = $this->em->find(Invoice::class, $invoiceId);
        self::assertSame(InvoiceStatus::PAID, $reloaded->getStatus());
        // The second call was a no-op: reference and paidAt stay from the first.
        self::assertSame('NTP-1', $reloaded->getExternalId());
        self::assertSame($paidAt, $reloaded->getPaidAt()->format('Y-m-d H:i:s'));
    }

    public function testMarkPaidPersistsDurablePaymentSucceededNotificationRow(): void
    {
        [, $invoice] = $this->createSubscriptionWithPendingInvoice();
        $invoiceId = $invoice->getId();

        $this->invoicing->markPaid($invoice, 'NTP-1', 'webhook');

        $this->em->clear();

        $notification = $this->em->getRepository(Notification::class)
            ->findOneBy(['dedupKey' => 'payment_succeeded:' . $invoiceId]);

        self::assertNotNull($notification, 'a durable in-app notification row must land for a settled payment');
        self::assertSame(NotificationType::PAYMENT_SUCCEEDED->value, $notification->getType());
        self::assertSame($this->user->getId(), $notification->getUser()->getId());
        self::assertNull($notification->getLegalCase());
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        // markPaid dispatches a PAYMENT_SUCCEEDED in-app notification (user FK, no cascade).
        $conn->executeStatement('DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE i FROM invoice i JOIN user u ON i.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE s FROM subscription s JOIN user u ON s.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM plan WHERE name LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
