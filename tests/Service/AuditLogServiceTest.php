<?php

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuditLogServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuditLogService $auditLogService;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->auditLogService = static::getContainer()->get(AuditLogService::class);
        $this->testPrefix = 'audit-svc-test-' . uniqid();
    }

    public function testLogCreatesAuditLogWithCorrectData(): void
    {
        $auditLog = $this->auditLogService->log(
            'test_action',
            'TestEntity',
            '123',
            ['old' => 'value'],
            ['new' => 'value'],
        );
        $this->em->flush();

        $this->assertInstanceOf(AuditLog::class, $auditLog);
        $this->assertSame('test_action', $auditLog->getAction());
        $this->assertSame('TestEntity', $auditLog->getEntityType());
        $this->assertSame('123', $auditLog->getEntityId());
        $this->assertSame(['old' => 'value'], $auditLog->getOldData());
        $this->assertSame(['new' => 'value'], $auditLog->getNewData());

        // Cleanup
        $this->em->remove($auditLog);
        $this->em->flush();
    }

    public function testLogHandlesNullOldAndNewData(): void
    {
        $auditLog = $this->auditLogService->log('test_null', 'TestEntity', '456');
        $this->em->flush();

        $this->assertNull($auditLog->getOldData());
        $this->assertNull($auditLog->getNewData());

        $this->em->remove($auditLog);
        $this->em->flush();
    }

    public function testLogSetsIpAddressFromRequest(): void
    {
        // In KernelTestCase context, there's no real request, so IP should be null
        $auditLog = $this->auditLogService->log('test_ip', 'TestEntity', '789');
        $this->em->flush();

        // No request in KernelTestCase, so IP is null
        $this->assertNull($auditLog->getIpAddress());

        $this->em->remove($auditLog);
        $this->em->flush();
    }

    public function testLogSetsNullUserInUnauthenticatedContext(): void
    {
        // No authenticated user in KernelTestCase
        $auditLog = $this->auditLogService->log('test_no_user', 'TestEntity', '000');
        $this->em->flush();

        $this->assertNull($auditLog->getUser());

        $this->em->remove($auditLog);
        $this->em->flush();
    }

    public function testLogPersistsToDatabase(): void
    {
        $auditLog = $this->auditLogService->log('test_persist', 'TestEntity', 'db-check');
        $this->em->flush();

        $id = $auditLog->getId();
        $this->em->clear();

        $found = $this->em->getRepository(AuditLog::class)->find($id);
        $this->assertNotNull($found);
        $this->assertSame('test_persist', $found->getAction());

        $this->em->remove($found);
        $this->em->flush();
    }
}
