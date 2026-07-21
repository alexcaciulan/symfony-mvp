<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\InvoiceType;
use PHPUnit\Framework\TestCase;

class InvoiceTypeTest extends TestCase
{
    public function testHasThreeCases(): void
    {
        $this->assertCount(3, InvoiceType::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (InvoiceType::cases() as $case) {
            $this->assertStringStartsWith('enum.invoice_type.', $case->label());
        }
    }

    public function testCanCreateFromValue(): void
    {
        $this->assertSame(InvoiceType::PLAN_CHANGE, InvoiceType::from('plan_change'));
        $this->assertNull(InvoiceType::tryFrom('nope'));
    }
}
