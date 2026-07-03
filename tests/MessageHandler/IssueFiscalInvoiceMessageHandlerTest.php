<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\FiscalInvoice;
use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\EInvoiceStatus;
use App\Enum\InvoiceStatus;
use App\Enum\UserType;
use App\Message\IssueFiscalInvoiceMessage;
use App\MessageHandler\IssueFiscalInvoiceMessageHandler;
use App\Repository\FiscalInvoiceRepository;
use App\Repository\InvoiceRepository;
use App\Service\Billing\EInvoicing\EInvoicingException;
use App\Service\Billing\FiscalInvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class IssueFiscalInvoiceMessageHandlerTest extends TestCase
{
    private function fiscalUser(): User
    {
        return (new User())
            ->setType(UserType::AVOCAT)
            ->setCompanyName('Cabinet Test')
            ->setCui('RO123')
            ->setStreet('Str. Test 1')
            ->setCity('București');
    }

    private function paidInvoice(): Invoice
    {
        return (new Invoice())->setUser($this->fiscalUser())->setStatus(InvoiceStatus::PAID);
    }

    public function testSkipsWhenInvoiceNotFound(): void
    {
        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn(null);

        $service = $this->createMock(FiscalInvoiceService::class);
        $service->expects($this->never())->method('issueForPaidInvoice');

        $handler = new IssueFiscalInvoiceMessageHandler($invoices, $this->createMock(FiscalInvoiceRepository::class), $service, $this->createMock(EntityManagerInterface::class));
        $handler(new IssueFiscalInvoiceMessage(1));
    }

    public function testSkipsWhenInvoiceNotPaid(): void
    {
        $invoice = (new Invoice())->setUser(new User())->setStatus(InvoiceStatus::PENDING);

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $service = $this->createMock(FiscalInvoiceService::class);
        $service->expects($this->never())->method('issueForPaidInvoice');

        $handler = new IssueFiscalInvoiceMessageHandler($invoices, $this->createMock(FiscalInvoiceRepository::class), $service, $this->createMock(EntityManagerInterface::class));
        $handler(new IssueFiscalInvoiceMessage(1));
    }

    public function testIssuesWhenPaid(): void
    {
        $invoice = $this->paidInvoice();

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $service = $this->createMock(FiscalInvoiceService::class);
        $service->expects($this->once())->method('issueForPaidInvoice')->with($invoice);

        $handler = new IssueFiscalInvoiceMessageHandler($invoices, $this->createMock(FiscalInvoiceRepository::class), $service, $this->createMock(EntityManagerInterface::class));
        $handler(new IssueFiscalInvoiceMessage(1));
    }

    public function testRethrowsTransientErrorForRetry(): void
    {
        $invoice = $this->paidInvoice();

        $invoices = $this->createMock(InvoiceRepository::class);
        $invoices->method('find')->willReturn($invoice);

        $service = $this->createMock(FiscalInvoiceService::class);
        $service->method('issueForPaidInvoice')->willThrowException(EInvoicingException::transient('network'));

        $handler = new IssueFiscalInvoiceMessageHandler($invoices, $this->createMock(FiscalInvoiceRepository::class), $service, $this->createMock(EntityManagerInterface::class));

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

        $handler = new IssueFiscalInvoiceMessageHandler($invoices, $fiscalRepo, $service, $em);
        $handler(new IssueFiscalInvoiceMessage(1)); // must NOT throw

        $this->assertSame(EInvoiceStatus::ERROR, $draft->getEInvoiceStatus());
        $this->assertSame('invalid CIF', $draft->getEInvoiceError());
    }
}
