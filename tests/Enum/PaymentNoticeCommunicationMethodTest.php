<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\PaymentNoticeCommunicationMethod;
use PHPUnit\Framework\TestCase;

class PaymentNoticeCommunicationMethodTest extends TestCase
{
    public function testValuesPresent(): void
    {
        $this->assertSame(PaymentNoticeCommunicationMethod::EXECUTOR, PaymentNoticeCommunicationMethod::from('EXECUTOR'));
        $this->assertSame(PaymentNoticeCommunicationMethod::POSTA_RCD, PaymentNoticeCommunicationMethod::from('POSTA_RCD'));
    }

    public function testOnlyTwoCases(): void
    {
        $this->assertCount(2, PaymentNoticeCommunicationMethod::cases());
    }

    public function testAllCasesHaveLabels(): void
    {
        foreach (PaymentNoticeCommunicationMethod::cases() as $case) {
            $this->assertSame('enum.payment_notice_communication_method.' . $case->value, $case->label());
        }
    }
}
