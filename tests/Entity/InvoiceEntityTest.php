<?php

namespace App\Tests\Entity;

use App\Entity\Invoice;
use App\Entity\LegalCase;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use PHPUnit\Framework\TestCase;

class InvoiceEntityTest extends TestCase
{
    public function testDefaults(): void
    {
        $invoice = new Invoice();

        $this->assertSame(InvoiceStatus::PENDING, $invoice->getStatus());
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
        $invoice->setType(InvoiceType::CASE_EXTRA);
        $invoice->setStatus(InvoiceStatus::CANCELED);
        $invoice->setExternalId('in_xyz');

        $this->assertSame($user, $invoice->getUser());
        $this->assertSame($subscription, $invoice->getSubscription());
        $this->assertSame($case, $invoice->getLegalCase());
        $this->assertSame('99.00', $invoice->getAmount());
        $this->assertSame(InvoiceType::CASE_EXTRA, $invoice->getType());
        $this->assertSame(InvoiceStatus::CANCELED, $invoice->getStatus());
        $this->assertSame('in_xyz', $invoice->getExternalId());
    }

    public function testTargetPlanDefaultsToNull(): void
    {
        $this->assertNull((new Invoice())->getTargetPlan());
    }

    public function testTargetPlanGetterAndSetter(): void
    {
        $invoice = new Invoice();
        $plan = new Plan();

        $this->assertSame($invoice, $invoice->setTargetPlan($plan));
        $this->assertSame($plan, $invoice->getTargetPlan());

        $invoice->setTargetPlan(null);
        $this->assertNull($invoice->getTargetPlan());
    }

    public function testMarkPaidSetsStatusAndTimestamp(): void
    {
        $invoice = new Invoice();

        $invoice->markPaid();

        $this->assertSame(InvoiceStatus::PAID, $invoice->getStatus());
        $this->assertInstanceOf(\DateTimeImmutable::class, $invoice->getPaidAt());
    }
}
