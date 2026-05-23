<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\LegalCase;
use App\EventSubscriber\PortalMonitoringSubscriber;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PortalMonitoringSubscriberTest extends TestCase
{
    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function dispatch(LegalCase $case, array $changeSet, LoggerInterface $logger): void
    {
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn($changeSet);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $args = new PostUpdateEventArgs($case, $em);

        (new PortalMonitoringSubscriber($logger))->postUpdate($args);
    }

    public function testLogsWhenMonitoringActivated(): void
    {
        $case = new LegalCase();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $this->dispatch($case, ['portalMonitoringActive' => [false, true]], $logger);
    }

    public function testLogsWhenCourtCaseNumberSetForFirstTime(): void
    {
        $case = new LegalCase();
        $case->setCourtCaseNumber('4521/302/2026');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $this->dispatch($case, ['courtCaseNumber' => [null, '4521/302/2026']], $logger);
    }

    public function testDoesNotLogForUnrelatedChange(): void
    {
        $case = new LegalCase();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $this->dispatch($case, ['notes' => [null, 'oarecare']], $logger);
    }

    public function testDoesNotLogWhenMonitoringDeactivated(): void
    {
        $case = new LegalCase();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $this->dispatch($case, ['portalMonitoringActive' => [true, false]], $logger);
    }

    public function testIgnoresNonLegalCaseEntities(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getUnitOfWork');

        $args = new PostUpdateEventArgs(new \stdClass(), $em);

        (new PortalMonitoringSubscriber($logger))->postUpdate($args);
    }
}
