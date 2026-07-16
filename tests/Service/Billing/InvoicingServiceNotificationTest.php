<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use App\Enum\NotificationType;
use App\Repository\InvoiceRepository;
use App\Service\AuditLogService;
use App\Service\Billing\InvoicingService;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Unit coverage for the PAYMENT_SUCCEEDED fan-out in {@see InvoicingService::markPaid}:
 * the settling call dispatches exactly one NotificationDispatch (right type, recipient,
 * dedup key), and a replay on the already-settled invoice dispatches nothing.
 */
#[AllowMockObjectsWithoutExpectations]
final class InvoicingServiceNotificationTest extends TestCase
{
    private function pendingInvoice(int $id, User $user): Invoice
    {
        $invoice = (new Invoice())
            ->setUser($user)
            ->setStatus(InvoiceStatus::PENDING)
            ->setType(InvoiceType::SUBSCRIPTION)
            ->setAmount('49.00');
        (new \ReflectionProperty(Invoice::class, 'id'))->setValue($invoice, $id);

        return $invoice;
    }

    /**
     * Builds the service with an EM whose transaction wrapper runs the closure
     * inline and whose find() returns the same invoice instance, so markPaid()
     * flips its status in place (a replay then sees PAID and no-ops).
     */
    private function service(Invoice $invoice, NotificationDispatcherInterface $notifier): InvoicingService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $work) => $work());
        $em->method('find')->willReturn($invoice);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new InvoicingService(
            $em,
            $this->createMock(InvoiceRepository::class),
            $this->createMock(AuditLogService::class),
            $bus,
            $notifier,
            $translator,
        );
    }

    public function testSettlingCallDispatchesPaymentSucceededOnce(): void
    {
        $user = (new User())->setEmail('lawyer@test.com');
        $invoice = $this->pendingInvoice(42, $user);

        $dispatchCount = 0;
        $captured = null;
        $notifier = $this->createMock(NotificationDispatcherInterface::class);
        // Reader closure captures by reference: an fn() arrow would freeze the null.
        $notifier->method('dispatch')->willReturnCallback(function (NotificationDispatch $d) use (&$dispatchCount, &$captured): void {
            ++$dispatchCount;
            $captured = $d;
        });

        $service = $this->service($invoice, $notifier);
        $service->markPaid($invoice, 'NTP-42', 'webhook');

        self::assertSame(1, $dispatchCount);
        self::assertInstanceOf(NotificationDispatch::class, $captured);
        self::assertSame(NotificationType::PAYMENT_SUCCEEDED, $captured->type);
        self::assertSame($user, $captured->user);
        self::assertNull($captured->legalCase);
        self::assertSame('payment_succeeded:42', $captured->dedupKey);
    }

    public function testAlreadySettledReplayDoesNotDispatchAgain(): void
    {
        $user = (new User())->setEmail('lawyer@test.com');
        $invoice = $this->pendingInvoice(7, $user);

        $dispatchCount = 0;
        $notifier = $this->createMock(NotificationDispatcherInterface::class);
        $notifier->method('dispatch')->willReturnCallback(function (NotificationDispatch $d) use (&$dispatchCount): void {
            ++$dispatchCount;
        });

        $service = $this->service($invoice, $notifier);

        // First call settles PENDING -> PAID and notifies.
        $service->markPaid($invoice, 'NTP-7', 'webhook');
        // Replay: find() now returns the same PAID invoice, so the guard no-ops.
        $service->markPaid($invoice, 'NTP-7-dup', 'reconciliation');

        self::assertSame(1, $dispatchCount);
    }
}
