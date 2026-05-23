<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\LegalCase;
use App\Message\CheckCasePortalMessage;
use App\MessageHandler\CheckCasePortalMessageHandler;
use App\Repository\LegalCaseRepository;
use App\Service\Portal\CaseMonitoringService;
use App\Service\Portal\PortalJustException;
use PHPUnit\Framework\TestCase;

class CheckCasePortalMessageHandlerTest extends TestCase
{
    public function testInvokesMonitorCaseForExistingCase(): void
    {
        $case = new LegalCase();

        $repo = $this->createStub(LegalCaseRepository::class);
        $repo->method('find')->willReturn($case);

        $monitoring = $this->createMock(CaseMonitoringService::class);
        $monitoring->expects($this->once())->method('monitorCase')->with($case)->willReturn(0);

        (new CheckCasePortalMessageHandler($repo, $monitoring))(new CheckCasePortalMessage(42));
    }

    public function testSkipsMissingCaseWithoutCallingMonitor(): void
    {
        $repo = $this->createStub(LegalCaseRepository::class);
        $repo->method('find')->willReturn(null);

        $monitoring = $this->createMock(CaseMonitoringService::class);
        $monitoring->expects($this->never())->method('monitorCase');

        (new CheckCasePortalMessageHandler($repo, $monitoring))(new CheckCasePortalMessage(999));
    }

    public function testSkipsDeletedCaseWithoutCallingMonitor(): void
    {
        $case = new LegalCase();
        $case->setDeletedAt(new \DateTimeImmutable());

        $repo = $this->createStub(LegalCaseRepository::class);
        $repo->method('find')->willReturn($case);

        $monitoring = $this->createMock(CaseMonitoringService::class);
        $monitoring->expects($this->never())->method('monitorCase');

        (new CheckCasePortalMessageHandler($repo, $monitoring))(new CheckCasePortalMessage(7));
    }

    public function testPropagatesPortalExceptionForRetry(): void
    {
        $case = new LegalCase();

        $repo = $this->createStub(LegalCaseRepository::class);
        $repo->method('find')->willReturn($case);

        $monitoring = $this->createStub(CaseMonitoringService::class);
        $monitoring->method('monitorCase')->willThrowException(new PortalJustException('SOAP down'));

        $this->expectException(PortalJustException::class);

        (new CheckCasePortalMessageHandler($repo, $monitoring))(new CheckCasePortalMessage(1));
    }
}
