<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\Invoice;
use App\Entity\LegalCase;
use App\Entity\Plan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FiscalInvoiceStatus;
use App\Enum\InvoiceType;
use App\Enum\UserType;
use App\Service\Billing\FiscalInvoiceFactory;
use App\Service\Billing\VatCalculator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class FiscalInvoiceFactoryTest extends KernelTestCase
{
    private const SUPPLIER = [
        'name' => 'LexRecovery SRL',
        'cui' => 'RO12345678',
        'onrcNumber' => 'J40/1/2026',
        'address' => 'București',
        'iban' => 'RO00BANK000',
    ];

    private FiscalInvoiceFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $this->factory = new FiscalInvoiceFactory(new VatCalculator(), $translator, self::SUPPLIER, 19, 'LEX', 'LEX', 15);
    }

    public function testBuildsDraftWithSupplierBuyerLinesAndTotals(): void
    {
        $invoice = $this->subscriptionInvoice($this->companyUser(), 'Starter', '99.00');

        $fiscal = $this->factory->buildDraft($invoice);

        $this->assertSame(FiscalInvoiceStatus::DRAFT, $fiscal->getStatus());
        $this->assertSame($invoice, $fiscal->getInvoice());
        $this->assertNull($fiscal->getSeries(), 'series is allocated at issuance, not at build');

        $this->assertSame('LexRecovery SRL', $fiscal->getSupplier()->name);
        $this->assertSame('Cabinet Test', $fiscal->getBuyer()->name);
        $this->assertSame('RO99', $fiscal->getBuyer()->cui);

        $this->assertCount(1, $fiscal->getLines());
        $line = $fiscal->getLines()->first();
        $this->assertStringContainsString('Starter', $line->getDescription());
        $this->assertSame('99.00', $line->getUnitPriceNet());

        $this->assertSame('99.00', $fiscal->getNetTotal());
        $this->assertSame('18.81', $fiscal->getVatTotal());
        $this->assertSame('117.81', $fiscal->getGrossTotal());
    }

    public function testBuyerSnapshotIsImmutableAgainstLaterUserEdits(): void
    {
        $user = $this->companyUser();
        $invoice = $this->subscriptionInvoice($user, 'Pro', '299.00');

        $fiscal = $this->factory->buildDraft($invoice);

        // Mutating the source user after the snapshot must NOT rewrite the invoice.
        $user->setCompanyName('SCA Renamed');
        $user->setCui('RO_CHANGED');

        $this->assertSame('Cabinet Test', $fiscal->getBuyer()->name);
        $this->assertSame('RO99', $fiscal->getBuyer()->cui);
    }

    public function testCaseExtraInvoiceDescription(): void
    {
        $case = (new LegalCase())->setCaseNumber('DOS-123');
        $invoice = (new Invoice())
            ->setUser($this->companyUser())
            ->setLegalCase($case)
            ->setType(InvoiceType::CASE_EXTRA)
            ->setAmount('49.00');

        $fiscal = $this->factory->buildDraft($invoice);

        $this->assertStringContainsString('DOS-123', $fiscal->getLines()->first()->getDescription());
        $this->assertSame('49.00', $fiscal->getNetTotal());
    }

    public function testPlanChangeInvoiceDescriptionUsesTheTargetPlan(): void
    {
        $sub = (new Subscription())->setPlan((new Plan())->setName('Starter'));
        $invoice = (new Invoice())
            ->setUser($this->companyUser())
            ->setSubscription($sub)
            ->setTargetPlan((new Plan())->setName('Pro'))
            ->setType(InvoiceType::PLAN_CHANGE)
            ->setAmount('299.00');

        // Regression: adding a case to InvoiceType left both match expressions in
        // this factory non-exhaustive, so a paid upgrade threw UnhandledMatchError
        // and its fiscal invoice was never issued.
        $fiscal = $this->factory->buildDraft($invoice);

        $description = $fiscal->getLines()->first()->getDescription();
        // The subscription still holds the old plan at issuance, since the change
        // is applied on settlement.
        $this->assertStringContainsString('Pro', $description);
        $this->assertStringNotContainsString('Starter', $description);
        $this->assertSame('299.00', $fiscal->getNetTotal());
    }

    public function testEveryInvoiceTypeCanBuildADraft(): void
    {
        // Guards the two match expressions against a future enum case being added
        // without a corresponding branch here.
        foreach (InvoiceType::cases() as $type) {
            $invoice = (new Invoice())
                ->setUser($this->companyUser())
                ->setSubscription((new Subscription())->setPlan((new Plan())->setName('Starter')))
                ->setLegalCase((new LegalCase())->setCaseNumber('DOS-1'))
                ->setTargetPlan((new Plan())->setName('Pro'))
                ->setType($type)
                ->setAmount('99.00');

            $fiscal = $this->factory->buildDraft($invoice);
            $this->assertNotSame('', $fiscal->getLines()->first()->getDescription(), $type->value);
        }
    }

    public function testBuildIssueRequestCarriesClientLinesCollectAndIdempotencyKey(): void
    {
        $invoice = $this->subscriptionInvoice($this->companyUser(), 'Starter', '99.00');
        $idProperty = new \ReflectionProperty(Invoice::class, 'id');
        $idProperty->setValue($invoice, 777);

        $draft = $this->factory->buildDraft($invoice);
        $request = $this->factory->buildIssueRequest($draft);

        $this->assertSame('LEX', $request->seriesName);
        $this->assertSame('RO12345678', $request->supplierCif);
        $this->assertSame('Cabinet Test', $request->client->name);
        $this->assertSame('RO99', $request->client->cui);
        $this->assertCount(1, $request->lines);
        $this->assertSame('99.00', $request->lines[0]->unitPriceNet);
        $this->assertSame('19', $request->lines[0]->vatPercentage);
        $this->assertSame('777', $request->idempotencyKey);
        $this->assertNotNull($request->collect);
        $this->assertSame('117.81', $request->collect->value);
    }

    private function companyUser(): User
    {
        $user = new User();
        $user->setType(UserType::PJ);
        $user->setCompanyName('Cabinet Test');
        $user->setCui('RO99');
        $user->setEmail('cabinet@test.ro');
        $user->setCity('Cluj');

        return $user;
    }

    private function subscriptionInvoice(User $user, string $planName, string $amount): Invoice
    {
        $plan = (new Plan())->setName($planName);
        $subscription = (new Subscription())->setUser($user)->setPlan($plan);

        return (new Invoice())
            ->setUser($user)
            ->setSubscription($subscription)
            ->setType(InvoiceType::SUBSCRIPTION)
            ->setAmount($amount);
    }
}
