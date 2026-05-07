<?php

namespace App\Tests\Entity;

use App\Entity\Invoice;
use App\Entity\LegalCase;
use App\Entity\Subscription;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class InvoiceEntityTest extends TestCase
{
    public function testDefaults(): void
    {
        $invoice = new Invoice();

        $this->assertSame('pending', $invoice->getStatus());
        $this->assertNull($invoice->getPaidAt());
        $this->assertNull($invoice->getSubscription());
        $this->assertNull($invoice->getLegalCase());
        $this->assertNull($invoice->getExternalId());
    }

    public function testGettersAndSetters(): void
    {
        $invoice = new Invoice();
        $user = new User();
        $subscription = new Subscription();
        $case = new LegalCase();

        $invoice->setUser($user);
        $invoice->setSubscription($subscription);
        $invoice->setLegalCase($case);
        $invoice->setAmount('99.00');
        $invoice->setType('case_extra');
        $invoice->setStatus('failed');
        $invoice->setExternalId('in_xyz');

        $this->assertSame($user, $invoice->getUser());
        $this->assertSame($subscription, $invoice->getSubscription());
        $this->assertSame($case, $invoice->getLegalCase());
        $this->assertSame('99.00', $invoice->getAmount());
        $this->assertSame('case_extra', $invoice->getType());
        $this->assertSame('failed', $invoice->getStatus());
        $this->assertSame('in_xyz', $invoice->getExternalId());
    }

    public function testMarkPaidSetsStatusAndTimestamp(): void
    {
        $invoice = new Invoice();

        $invoice->markPaid();

        $this->assertSame('paid', $invoice->getStatus());
        $this->assertInstanceOf(\DateTimeImmutable::class, $invoice->getPaidAt());
    }
}
