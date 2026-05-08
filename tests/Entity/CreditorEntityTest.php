<?php

namespace App\Tests\Entity;

use App\Entity\Creditor;
use App\Entity\User;
use App\Enum\PersonType;
use PHPUnit\Framework\TestCase;

class CreditorEntityTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $creditor = new Creditor();
        $user = new User();

        $creditor->setUser($user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Creditor SRL');
        $creditor->setTaxId('RO12345678');
        $creditor->setPersonalId('1900101123456');
        $creditor->setTradeRegistryNumber('J40/123/2020');
        $creditor->setAddress('Str. Test nr. 1, București');
        $creditor->setEmail('contact@creditor.ro');
        $creditor->setPhone('+40712345678');
        $creditor->setIban('RO49AAAA1B31007593840000');
        $creditor->setLegalRepresentative('Ion Popescu');

        $this->assertSame($user, $creditor->getUser());
        $this->assertSame(PersonType::PJ, $creditor->getPersonType());
        $this->assertSame('SC Creditor SRL', $creditor->getName());
        $this->assertSame('RO12345678', $creditor->getTaxId());
        $this->assertSame('1900101123456', $creditor->getPersonalId());
        $this->assertSame('J40/123/2020', $creditor->getTradeRegistryNumber());
        $this->assertSame('Str. Test nr. 1, București', $creditor->getAddress());
        $this->assertSame('contact@creditor.ro', $creditor->getEmail());
        $this->assertSame('+40712345678', $creditor->getPhone());
        $this->assertSame('RO49AAAA1B31007593840000', $creditor->getIban());
        $this->assertSame('Ion Popescu', $creditor->getLegalRepresentative());
    }

    public function testCreatedAtSetInConstructor(): void
    {
        $creditor = new Creditor();

        $this->assertInstanceOf(\DateTimeImmutable::class, $creditor->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $creditor->getUpdatedAt());
    }

    public function testToStringReturnsName(): void
    {
        $creditor = new Creditor();
        $creditor->setName('SC Test SRL');

        $this->assertSame('SC Test SRL', (string) $creditor);
    }

    public function testLegalCasesCollectionEmptyByDefault(): void
    {
        $creditor = new Creditor();

        $this->assertCount(0, $creditor->getLegalCases());
    }
}
