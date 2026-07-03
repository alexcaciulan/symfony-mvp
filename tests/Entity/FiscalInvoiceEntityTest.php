<?php

namespace App\Tests\Entity;

use App\DTO\Billing\PartySnapshot;
use App\Entity\FiscalInvoice;
use App\Entity\FiscalInvoiceLine;
use App\Enum\EInvoiceStatus;
use App\Enum\FiscalInvoiceKind;
use App\Enum\FiscalInvoiceStatus;
use PHPUnit\Framework\TestCase;

class FiscalInvoiceEntityTest extends TestCase
{
    public function testDefaults(): void
    {
        $fiscal = new FiscalInvoice();

        $this->assertSame(FiscalInvoiceStatus::DRAFT, $fiscal->getStatus());
        $this->assertSame(FiscalInvoiceKind::INVOICE, $fiscal->getKind());
        $this->assertSame(EInvoiceStatus::NOT_APPLICABLE, $fiscal->getEInvoiceStatus());
        $this->assertSame('RON', $fiscal->getCurrency());
        $this->assertNull($fiscal->getSeries());
        $this->assertNull($fiscal->getNumber());
        $this->assertNull($fiscal->getFormattedNumber());
        $this->assertNull($fiscal->getSupplier());
        $this->assertNull($fiscal->getBuyer());
        $this->assertCount(0, $fiscal->getLines());
    }

    public function testSnapshotJsonRoundTrip(): void
    {
        $supplier = new PartySnapshot(name: 'LexRecovery SRL', cui: 'RO1', address: 'București');
        $buyer = new PartySnapshot(name: 'Cabinet X', cui: 'RO2', address: 'Cluj');

        $fiscal = (new FiscalInvoice())->setSupplier($supplier)->setBuyer($buyer);

        $this->assertEquals($supplier, $fiscal->getSupplier());
        $this->assertEquals($buyer, $fiscal->getBuyer());
        $this->assertSame('LexRecovery SRL', $fiscal->getSupplier()->name);
        $this->assertSame('Cabinet X', $fiscal->getBuyer()->name);
    }

    public function testAddLineWiresBackReference(): void
    {
        $fiscal = new FiscalInvoice();
        $line = (new FiscalInvoiceLine())
            ->setDescription('Abonament')
            ->setUnitPriceNet('99.00')
            ->setVatRate('19.00')
            ->setLineNet('99.00')
            ->setLineVat('18.81')
            ->setLineGross('117.81');

        $fiscal->addLine($line);

        $this->assertCount(1, $fiscal->getLines());
        $this->assertSame($fiscal, $line->getFiscalInvoice());
    }

    public function testFormattedNumberOnceIssued(): void
    {
        $fiscal = (new FiscalInvoice())->setSeries('LEX')->setNumber('0042');

        $this->assertSame('LEX 0042', $fiscal->getFormattedNumber());
    }
}
