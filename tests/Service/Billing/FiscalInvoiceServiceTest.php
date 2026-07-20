<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\DTO\Billing\EInvoicing\EInvoiceCancelResult;
use App\DTO\Billing\EInvoicing\EInvoiceIssueResult;
use App\DTO\Billing\EInvoicing\EInvoiceStatusResult;
use App\DTO\Billing\PartySnapshot;
use App\Entity\FiscalInvoice;
use App\Entity\FiscalInvoiceLine;
use App\Entity\Invoice;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\EInvoiceStatus;
use App\Enum\FiscalInvoiceKind;
use App\Enum\FiscalInvoiceStatus;
use App\Enum\InvoiceType;
use App\Enum\UserType;
use App\Repository\FiscalInvoiceRepository;
use App\Service\AuditLogService;
use App\Service\Billing\EInvoicing\EInvoicingProviderInterface;
use App\Service\Billing\FiscalInvoiceFactory;
use App\Service\Billing\FiscalInvoiceService;
use App\Service\Billing\VatCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// Each test constructs the service with a mix of behaviour-verified mocks
// (->expects) and plain collaborator doubles; the latter intentionally carry no
// expectations, so opt out of PHPUnit's mock-without-expectations notice.
#[AllowMockObjectsWithoutExpectations]
class FiscalInvoiceServiceTest extends TestCase
{
    private function factory(): FiscalInvoiceFactory
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Abonament Starter');

        return new FiscalInvoiceFactory(
            new VatCalculator(),
            $translator,
            ['name' => 'LexRecovery SRL', 'cui' => 'RO123'],
            19,
            'LEX',
            'LEX',
            15,
        );
    }

    private function paidInvoice(int $id = 5): Invoice
    {
        $user = (new User())->setType(UserType::PJ)->setCompanyName('Cabinet')->setCui('RO9')->setEmail('a@b.ro');
        $plan = (new Plan())->setName('Starter');
        $sub = (new Subscription())->setUser($user)->setPlan($plan);
        $invoice = (new Invoice())->setUser($user)->setSubscription($sub)->setType(InvoiceType::SUBSCRIPTION)->setAmount('99.00');

        $idProperty = new \ReflectionProperty(Invoice::class, 'id');
        $idProperty->setValue($invoice, $id);

        return $invoice;
    }

    public function testIssueAppliesProviderResultAndMarksIssued(): void
    {
        $invoice = $this->paidInvoice(5);

        $repo = $this->createMock(FiscalInvoiceRepository::class);
        $repo->method('findOneByInvoice')->willReturn(null);

        $provider = $this->createMock(EInvoicingProviderInterface::class);
        $provider->expects($this->once())->method('issue')->willReturn(
            new EInvoiceIssueResult('stub', 'LEX', '00005', 'pid-5', EInvoiceStatus::NOT_APPLICABLE, null, null, null),
        );

        $em = $this->createMock(EntityManagerInterface::class);
        // DRAFT persisted before issuing (reconcilable), then a flush per stage.
        $em->expects($this->once())->method('persist');
        $em->expects($this->exactly(2))->method('flush');

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log');

        $service = new FiscalInvoiceService($em, $repo, $this->factory(), $provider, $audit);
        $fiscal = $service->issueForPaidInvoice($invoice);

        $this->assertSame(FiscalInvoiceStatus::ISSUED, $fiscal->getStatus());
        $this->assertSame('LEX', $fiscal->getSeries());
        $this->assertSame('00005', $fiscal->getNumber());
        $this->assertSame('pid-5', $fiscal->getProviderInvoiceId());
        $this->assertSame('117.81', $fiscal->getGrossTotal());
    }

    public function testIssueReusesExistingDraftWithoutProviderInvoiceId(): void
    {
        $invoice = $this->paidInvoice(8);
        $draft = (new FiscalInvoice())->setInvoice($invoice)->setUser($invoice->getUser());
        // Simulate a DRAFT already loaded from the DB (has an id, already managed).
        (new \ReflectionProperty(FiscalInvoice::class, 'id'))->setValue($draft, 33);

        $repo = $this->createMock(FiscalInvoiceRepository::class);
        $repo->method('findOneByInvoice')->willReturn($draft);

        $provider = $this->createMock(EInvoicingProviderInterface::class);
        $provider->expects($this->once())->method('issue')->willReturn(
            new EInvoiceIssueResult('stub', 'LEX', '00008', 'pid-8'),
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new FiscalInvoiceService($em, $repo, $this->factory(), $provider, $this->createMock(AuditLogService::class));
        $result = $service->issueForPaidInvoice($invoice);

        $this->assertSame($draft, $result, 'the existing DRAFT is completed, not rebuilt');
        $this->assertSame('pid-8', $result->getProviderInvoiceId());
        $this->assertSame(FiscalInvoiceStatus::ISSUED, $result->getStatus());
    }

    public function testIssueIsIdempotentWhenAlreadyIssued(): void
    {
        $invoice = $this->paidInvoice(5);

        $already = (new FiscalInvoice())->setUser($invoice->getUser())->setProviderInvoiceId('pid-existing');

        $repo = $this->createMock(FiscalInvoiceRepository::class);
        $repo->method('findOneByInvoice')->willReturn($already);

        $provider = $this->createMock(EInvoicingProviderInterface::class);
        $provider->expects($this->never())->method('issue');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $service = new FiscalInvoiceService($em, $repo, $this->factory(), $provider, $this->createMock(AuditLogService::class));
        $result = $service->issueForPaidInvoice($invoice);

        $this->assertSame($already, $result);
    }

    public function testSyncEInvoiceStatusAppliesProviderStatus(): void
    {
        $fiscal = (new FiscalInvoice())->setUser(new User())->setSeries('FCT')->setNumber('0001')->setProviderInvoiceId('FCT/0001');

        $provider = $this->createMock(EInvoicingProviderInterface::class);
        $provider->method('fetchEInvoiceStatus')->willReturn(new EInvoiceStatusResult(EInvoiceStatus::ACCEPTED, 'spv-9'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = new FiscalInvoiceService($em, $this->createMock(FiscalInvoiceRepository::class), $this->factory(), $provider, $this->createMock(AuditLogService::class));
        $service->syncEInvoiceStatus($fiscal);

        $this->assertSame(EInvoiceStatus::ACCEPTED, $fiscal->getEInvoiceStatus());
        $this->assertSame('spv-9', $fiscal->getSpvId());
    }

    public function testStornoCreatesNegatedCorrectionAndCancelsOriginal(): void
    {
        $original = (new FiscalInvoice())
            ->setUser(new User())
            ->setStatus(FiscalInvoiceStatus::ISSUED)
            ->setSeries('FCT')->setNumber('0001')->setProviderName('oblio')
            ->setSupplier(new PartySnapshot(name: 'LexRecovery SRL', cui: 'RO123'))
            ->setBuyer(new PartySnapshot(name: 'Cabinet X', cui: 'RO99'))
            ->setNetTotal('99.00')->setVatTotal('18.81')->setGrossTotal('117.81');
        $original->addLine((new FiscalInvoiceLine())
            ->setDescription('Abonament')->setQuantity('1.000')->setUnitPriceNet('99.00')
            ->setVatRate('19')->setLineNet('99.00')->setLineVat('18.81')->setLineGross('117.81'));

        $provider = $this->createMock(EInvoicingProviderInterface::class);
        $provider->method('storno')->willReturn(new EInvoiceCancelResult(success: true, stornoSeries: 'FCT', stornoNumber: '0002'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new FiscalInvoiceService($em, $this->createMock(FiscalInvoiceRepository::class), $this->factory(), $provider, $this->createMock(AuditLogService::class));
        $storno = $service->storno($original, 'greșeală');

        $this->assertSame(FiscalInvoiceKind::STORNO, $storno->getKind());
        $this->assertSame($original, $storno->getStornoOf());
        $this->assertSame('0002', $storno->getNumber());
        $this->assertSame('-117.81', $storno->getGrossTotal());
        $this->assertSame('-99.00', $storno->getLines()->first()->getLineNet());
        $this->assertSame(FiscalInvoiceStatus::CANCELED, $original->getStatus());
    }

    public function testStornoThrowsWhenNotIssued(): void
    {
        $draft = (new FiscalInvoice())->setUser(new User()); // DRAFT by default

        $provider = $this->createMock(EInvoicingProviderInterface::class);
        $provider->expects($this->never())->method('storno');

        $service = new FiscalInvoiceService($this->createMock(EntityManagerInterface::class), $this->createMock(FiscalInvoiceRepository::class), $this->factory(), $provider, $this->createMock(AuditLogService::class));

        $this->expectException(\App\Service\Billing\EInvoicing\EInvoicingException::class);
        $service->storno($draft, 'x');
    }
}
