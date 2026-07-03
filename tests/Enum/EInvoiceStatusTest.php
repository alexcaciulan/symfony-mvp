<?php

namespace App\Tests\Enum;

use App\Enum\EInvoiceStatus;
use PHPUnit\Framework\TestCase;

class EInvoiceStatusTest extends TestCase
{
    public function testHasSixCases(): void
    {
        $this->assertCount(6, EInvoiceStatus::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (EInvoiceStatus::cases() as $case) {
            $this->assertStringStartsWith('enum.einvoice_status.', $case->label());
        }
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(EInvoiceStatus::NOT_APPLICABLE, EInvoiceStatus::from('not_applicable'));
        $this->assertSame(EInvoiceStatus::ACCEPTED, EInvoiceStatus::from('accepted'));
        $this->assertNull(EInvoiceStatus::tryFrom('nope'));
    }
}
