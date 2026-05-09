<?php

namespace App\Tests\Entity;

use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Enum\AnafStatus;
use App\Enum\PersonType;
use PHPUnit\Framework\TestCase;

class DebtorEntityTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $debtor = new Debtor();
        $case = new LegalCase();
        $bpiProof = new Document();
        $anafChecked = new \DateTimeImmutable('2026-05-09 10:00:00');
        $bpiChecked = new \DateTimeImmutable('2026-05-09 10:30:00');

        $debtor->setLegalCase($case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('Debitor SRL');
        $debtor->setCui('RO87654321');
        $debtor->setPersonalId('2900101654321');
        $debtor->setOnrcNumber('J12/456/2019');
        $debtor->setAddress('Str. Datoriei nr. 5, Cluj-Napoca');
        $debtor->setEmail('contact@debitor.ro');
        $debtor->setPhone('+40798765432');
        $debtor->setIban('RO99BBBB2C42008684951111');
        $debtor->setAdministrator('Maria Ionescu');
        $debtor->setAnafStatus(AnafStatus::ACTIV);
        $debtor->setAnafCheckedAt($anafChecked);
        $debtor->setInInsolvency(false);
        $debtor->setInsolvencyCheckedAt($bpiChecked);
        $debtor->setBpiProofDocument($bpiProof);
        $debtor->setBpiVerifiedNote('Verificat BPI 2026-05-09, fără mențiuni.');

        $this->assertSame($case, $debtor->getLegalCase());
        $this->assertSame(PersonType::PJ, $debtor->getPersonType());
        $this->assertSame('Debitor SRL', $debtor->getName());
        $this->assertSame('RO87654321', $debtor->getCui());
        $this->assertSame('2900101654321', $debtor->getPersonalId());
        $this->assertSame('J12/456/2019', $debtor->getOnrcNumber());
        $this->assertSame('Str. Datoriei nr. 5, Cluj-Napoca', $debtor->getAddress());
        $this->assertSame('contact@debitor.ro', $debtor->getEmail());
        $this->assertSame('+40798765432', $debtor->getPhone());
        $this->assertSame('RO99BBBB2C42008684951111', $debtor->getIban());
        $this->assertSame('Maria Ionescu', $debtor->getAdministrator());
        $this->assertSame(AnafStatus::ACTIV, $debtor->getAnafStatus());
        $this->assertSame($anafChecked, $debtor->getAnafCheckedAt());
        $this->assertFalse($debtor->isInInsolvency());
        $this->assertSame($bpiChecked, $debtor->getInsolvencyCheckedAt());
        $this->assertSame($bpiProof, $debtor->getBpiProofDocument());
        $this->assertSame('Verificat BPI 2026-05-09, fără mențiuni.', $debtor->getBpiVerifiedNote());
    }

    public function testNullableFieldsDefaultToNull(): void
    {
        $debtor = new Debtor();

        $this->assertNull($debtor->getCui());
        $this->assertNull($debtor->getPersonalId());
        $this->assertNull($debtor->getOnrcNumber());
        $this->assertNull($debtor->getEmail());
        $this->assertNull($debtor->getPhone());
        $this->assertNull($debtor->getIban());
        $this->assertNull($debtor->getAdministrator());
        $this->assertNull($debtor->getAnafStatus());
        $this->assertNull($debtor->getAnafCheckedAt());
        $this->assertNull($debtor->getInsolvencyCheckedAt());
        $this->assertNull($debtor->getBpiProofDocument());
        $this->assertNull($debtor->getBpiVerifiedNote());
    }

    public function testInInsolvencyDefaultsToFalse(): void
    {
        $debtor = new Debtor();

        $this->assertFalse($debtor->isInInsolvency());
    }

    public function testToStringReturnsName(): void
    {
        $debtor = new Debtor();
        $debtor->setName('Test Debitor SRL');

        $this->assertSame('Test Debitor SRL', (string) $debtor);
    }
}
