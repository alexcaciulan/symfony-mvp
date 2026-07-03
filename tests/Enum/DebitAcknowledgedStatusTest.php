<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DebitAcknowledgedStatus;
use PHPUnit\Framework\TestCase;

class DebitAcknowledgedStatusTest extends TestCase
{
    public function testValuesPresent(): void
    {
        $this->assertSame(DebitAcknowledgedStatus::PARTIAL, DebitAcknowledgedStatus::from('PARTIAL'));
        $this->assertSame(DebitAcknowledgedStatus::UNPAID, DebitAcknowledgedStatus::from('UNPAID'));
    }

    public function testOnlyTwoCases(): void
    {
        $this->assertCount(2, DebitAcknowledgedStatus::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (DebitAcknowledgedStatus::cases() as $case) {
            $this->assertSame('enum.debit_acknowledged_status.' . $case->value, $case->label());
        }
    }
}
