<?php

namespace App\Tests\Entity;

use App\Entity\Debtor;
use App\Entity\LegalCase;
use PHPUnit\Framework\TestCase;

class DebtorEntityTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $debtor = new Debtor();
        $case = new LegalCase();

        $debtor->setLegalCase($case);
        $debtor->setPersonType('PJ');
        $debtor->setName('Debitor SRL');
        $debtor->setTaxId('RO87654321');
        $debtor->setPersonalId('2900101654321');
        $debtor->setTradeRegistryNumber('J12/456/2019');
        $debtor->setAddress('Str. Datoriei nr. 5, Cluj-Napoca');
        $debtor->setEmail('contact@debitor.ro');
        $debtor->setPhone('+40798765432');
        $debtor->setIban('RO99BBBB2C42008684951111');
        $debtor->setAdministrator('Maria Ionescu');
        $debtor->setOnrcStatus('ACTIVE');

        $this->assertSame($case, $debtor->getLegalCase());
        $this->assertSame('PJ', $debtor->getPersonType());
        $this->assertSame('Debitor SRL', $debtor->getName());
        $this->assertSame('RO87654321', $debtor->getTaxId());
        $this->assertSame('2900101654321', $debtor->getPersonalId());
        $this->assertSame('J12/456/2019', $debtor->getTradeRegistryNumber());
        $this->assertSame('Str. Datoriei nr. 5, Cluj-Napoca', $debtor->getAddress());
        $this->assertSame('contact@debitor.ro', $debtor->getEmail());
        $this->assertSame('+40798765432', $debtor->getPhone());
        $this->assertSame('RO99BBBB2C42008684951111', $debtor->getIban());
        $this->assertSame('Maria Ionescu', $debtor->getAdministrator());
        $this->assertSame('ACTIVE', $debtor->getOnrcStatus());
    }

    public function testNullableFieldsDefaultToNull(): void
    {
        $debtor = new Debtor();

        $this->assertNull($debtor->getTaxId());
        $this->assertNull($debtor->getPersonalId());
        $this->assertNull($debtor->getTradeRegistryNumber());
        $this->assertNull($debtor->getEmail());
        $this->assertNull($debtor->getPhone());
        $this->assertNull($debtor->getIban());
        $this->assertNull($debtor->getAdministrator());
        $this->assertNull($debtor->getOnrcStatus());
    }

    public function testToStringReturnsName(): void
    {
        $debtor = new Debtor();
        $debtor->setName('Test Debitor SRL');

        $this->assertSame('Test Debitor SRL', (string) $debtor);
    }
}
