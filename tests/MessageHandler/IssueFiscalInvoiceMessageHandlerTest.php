<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\FiscalInvoice;
use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\EInvoiceStatus;
use App\Enum\InvoiceStatus;
use App\Enum\NotificationType;
use App\Enum\UserType;
use App\Message\IssueFiscalInvoiceMessage;
use App\MessageHandler\IssueFiscalInvoiceMessageHandler;
use App\Repository\FiscalInvoiceRepository;
use App\Repository\InvoiceRepository;
use App\Service\Billing\EInvoicing\EInvoicingException;
use App\Service\Billing\FiscalInvoiceService;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class IssueFiscalInvoiceMessageHandlerTest extends TestCase
{
    private function handlerWith(
        InvoiceRepository $invoices,
        FiscalInvoiceRepository $fiscalInvoices,
        FiscalInvoiceService $service,
        EntityManagerInterface $em,
    ): IssueFiscalInvoiceMessageHandler {
        return new IssueFiscalInvoiceMessageHandler(
            $invoices,
            $fiscalInvoices,
            $service,
            $em,
            $this->createMock(NotificationDispatcherInterface::class),
            $this->createMock(TranslatorInterface::class),
        );
    }

    private function fiscalUser(): User
    {
        return (new User())
            ->setType(UserType::AVOCAT)
            ->setCompanyName('Cabinet Test')
            ->setCui('RO123')
            ->setStreet('Str. Test 1')
            ->setCity('București');
    }

    private function paidInvoice(?int $id = null): Invoice
    {
        $invoice = (new Invoice())->setUser($this->fiscalUser())->setStatus(InvoiceStatus::PAID);
        if (null !== $id) {
            (new \ReflectionProperty(Invoice::class, 'id'))->setValue($invoice, $id);
        }

        return $invoice;
    }

    public function testSkipsWhenInvoiceNotFound(): void
    {
        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn(null);

        $service = $this->createMock(FiscalInvoiceService::class);
        $service->expects($this->never())->method('issueForPaidInvoice');

        $handler = $this->handlerWith($invoices, $this->createMock(FiscalInvoiceRepository::class), $service, $this->createMock(EntityManagerInterface::class));
        $handler(new IssueFiscalInvoiceMessage(1));
    }

    public function testSkipsWhenInvoiceNotPaid(): void
    {
        $invoice = (new Invoice())->setUser(new User())->setStatus(InvoiceStatus::PENDING);

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $service = $this->createMock(FiscalInvoiceService::class);
        $service->expects($this->never())->method('issueForPaidInvoice');

        $handler = $this->handlerWith($invoices, $this->createMock(FiscalInvoiceRepository::class), $service, $this->createMock(EntityManagerInterface::class));
        $handler(new IssueFiscalInvoiceMessage(1));
    }

    public function testIssuesWhenPaid(): void
    {
        $invoice = $this->paidInvoice();

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $service = $this->createMock(FiscalInvoiceService::class);
        $service->expects($this->once())->method('issueForPaidInvoice')->with($invoice);

        $handler = $this->handlerWith($invoices, $this->createMock(FiscalInvoiceRepository::class), $service, $this->createMock(EntityManagerInterface::class));
        $handler(new IssueFiscalInvoiceMessage(1));
    }

    public function testSuccessfulIssuanceDispatchesInvoiceIssuedNotification(): void
    {
        $invoice = $this->paidInvoice(7);

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $service = $this->createMock(FiscalInvoiceService::class);
        $service->method('issueForPaidInvoice')->willReturn(new FiscalInvoice());

        $captured = null;
        $notifier = $this->createMock(NotificationDispatcherInterface::class);
        // Reader closure captures by reference so the assertion sees the dispatched request.
        $notifier->method('dispatch')->willReturnCallback(function (NotificationDispatch $d) use (&$captured): void {
            $captured = $d;
        });

        $handler = new IssueFiscalInvoiceMessageHandler(
            $invoices,
            $this->createMock(FiscalInvoiceRepository::class),
            $service,
            $this->createMock(EntityManagerInterface::class),
            $notifier,
            $this->createMock(TranslatorInterface::class),
        );
        $handler(new IssueFiscalInvoiceMessage(7));

        $this->assertInstanceOf(NotificationDispatch::class, $captured);
        $this->assertSame(NotificationType::INVOICE_ISSUED, $captured->type);
        $this->assertSame($invoice->getUser(), $captured->user);
        $this->assertNull($captured->legalCase);
        $this->assertSame('invoice_issued:7', $captured->dedupKey);
    }

    public function testDoesNotNotifyWhenIssuanceFails(): void
    {
        $invoice = $this->paidInvoice(7);

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $service = $this->createMock(FiscalInvoiceService::class);
        $service->method('issueForPaidInvoice')->willThrowException(EInvoicingException::business('invalid CIF'));

        $notifier = $this->createMock(NotificationDispatcherInterface::class);
        $notifier->expects($this->never())->method('dispatch');

        $handler = new IssueFiscalInvoiceMessageHandler(
            $invoices,
            $this->createMock(FiscalInvoiceRepository::class),
            $service,
            $this->createMock(EntityManagerInterface::class),
            $notifier,
            $this->createMock(TranslatorInterface::class),
        );
        $handler(new IssueFiscalInvoiceMessage(7)); // business error swallowed, no notification
    }

    public function testRethrowsTransientErrorForRetry(): void
    {
        $invoice = $this->paidInvoice();

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $service = $this->createMock(FiscalInvoiceService::class);
        $service->method('issueForPaidInvoice')->willThrowException(EInvoicingException::transient('network'));

        $handler = $this->handlerWith($invoices, $this->createMock(FiscalInvoiceRepository::class), $service, $this->createMock(EntityManagerInterface::class));

        $this->expectException(EInvoicingException::class);
        $handler(new IssueFiscalInvoiceMessage(1));
    }

    public function testBusinessErrorMarksMirrorErrorAndSwallows(): void
    {
        $invoice = $this->paidInvoice();
        $draft = (new FiscalInvoice())->setUser($invoice->getUser());

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $service = $this->createMock(FiscalInvoiceService::class);
        $service->method('issueForPaidInvoice')->willThrowException(EInvoicingException::business('invalid CIF'));

        $fiscalRepo = $this->createMock(FiscalInvoiceRepository::class);
        $fiscalRepo->method('findOneByInvoice')->willReturn($draft);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $handler = $this->handlerWith($invoices, $fiscalRepo, $service, $em);
        $handler(new IssueFiscalInvoiceMessage(1)); // must NOT throw

        $this->assertSame(EInvoiceStatus::ERROR, $draft->getEInvoiceStatus());
        $this->assertSame('invalid CIF', $draft->getEInvoiceError());
    }
}
