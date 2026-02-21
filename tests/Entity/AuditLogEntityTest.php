<?php

namespace App\Tests\Entity;

use App\Entity\AuditLog;
use PHPUnit\Framework\TestCase;

class AuditLogEntityTest extends TestCase
{
    public function testGetOldDataJsonReturnsPrettyPrintedJson(): void
    {
        $log = new AuditLog();
        $log->setOldData(['status' => 'draft', 'amount' => 100]);

        $json = $log->getOldDataJson();
        $this->assertNotNull($json);
        $this->assertStringContainsString("\n", $json); // pretty print has newlines
        $decoded = json_decode($json, true);
        $this->assertSame('draft', $decoded['status']);
        $this->assertSame(100, $decoded['amount']);
    }

    public function testGetOldDataJsonPreservesUnicode(): void
    {
        $log = new AuditLog();
        $log->setOldData(['status' => 'În așteptare', 'name' => 'Popescu Ăăîîșșțț']);

        $json = $log->getOldDataJson();
        $this->assertStringContainsString('În așteptare', $json);
        $this->assertStringContainsString('Popescu Ăăîîșșțț', $json);
    }

    public function testGetOldDataJsonReturnsNullWhenNoData(): void
    {
        $log = new AuditLog();

        $this->assertNull($log->getOldDataJson());
    }

    public function testGetNewDataJsonReturnsPrettyPrintedJson(): void
    {
        $log = new AuditLog();
        $log->setNewData(['status' => 'paid']);

        $json = $log->getNewDataJson();
        $this->assertNotNull($json);
        $decoded = json_decode($json, true);
        $this->assertSame('paid', $decoded['status']);
    }

    public function testGetNewDataJsonReturnsNullWhenNoData(): void
    {
        $log = new AuditLog();

        $this->assertNull($log->getNewDataJson());
    }

    public function testCreatedAtSetInConstructor(): void
    {
        $before = new \DateTimeImmutable();
        $log = new AuditLog();
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $log->getCreatedAt()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $log->getCreatedAt()->getTimestamp());
    }
}
