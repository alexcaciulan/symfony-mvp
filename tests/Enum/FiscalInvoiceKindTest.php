<?php

namespace App\Tests\Enum;

use App\Enum\FiscalInvoiceKind;
use PHPUnit\Framework\TestCase;

class FiscalInvoiceKindTest extends TestCase
{
    public function testHasTwoCases(): void
    {
        $this->assertCount(2, FiscalInvoiceKind::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (FiscalInvoiceKind::cases() as $case) {
            $this->assertStringStartsWith('enum.fiscal_invoice_kind.', $case->label());
        }
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(FiscalInvoiceKind::INVOICE, FiscalInvoiceKind::from('invoice'));
        $this->assertSame(FiscalInvoiceKind::STORNO, FiscalInvoiceKind::from('storno'));
    }
}
