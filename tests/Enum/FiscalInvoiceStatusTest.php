<?php

namespace App\Tests\Enum;

use App\Enum\FiscalInvoiceStatus;
use PHPUnit\Framework\TestCase;

class FiscalInvoiceStatusTest extends TestCase
{
    public function testHasThreeCases(): void
    {
        $this->assertCount(3, FiscalInvoiceStatus::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (FiscalInvoiceStatus::cases() as $case) {
            $this->assertStringStartsWith('enum.fiscal_invoice_status.', $case->label());
        }
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(FiscalInvoiceStatus::DRAFT, FiscalInvoiceStatus::from('draft'));
        $this->assertSame(FiscalInvoiceStatus::ISSUED, FiscalInvoiceStatus::from('issued'));
        $this->assertSame(FiscalInvoiceStatus::CANCELED, FiscalInvoiceStatus::from('canceled'));
    }
}
