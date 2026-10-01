<?php

namespace App\Tests\Entity;

use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\PersonType;
use PHPUnit\Framework\TestCase;

class DebtorEntityTest extends TestCase
{
    public function testTheCompanyKeepsIdentityOnly(): void
    {
        $user = new User();
        $debtor = new Debtor();
        $debtor->setUser($user);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('Debitor SRL');
        $debtor->setCui('RO87654321');
        $debtor->setOnrcNumber('J12/456/2019');
        $debtor->setAddress('Str. Datoriei nr. 5, Cluj-Napoca');
        $debtor->setAddressCounty('Cluj');
        $debtor->setAddressLocality('Cluj-Napoca');
        $debtor->setEmail('contact@debitor.ro');
        $debtor->setPhone('+40798765432');
        $debtor->setIban('RO99BBBB2C42008684951111');
        $debtor->setAdministrator('Maria Ionescu');

        $this->assertSame($user, $debtor->getUser());
        $this->assertSame('Debitor SRL', $debtor->getName());
        $this->assertSame('RO87654321', $debtor->getCui(), 'the typed spelling is what the acts print');
        $this->assertSame('87654321', $debtor->getCuiKey());
        $this->assertSame('Cluj', $debtor->getAddressCounty());
        $this->assertSame('Maria Ionescu', $debtor->getAdministrator());
        $this->assertSame('Debitor SRL', (string) $debtor);
    }

    public function testTheCuiKeyFollowsTheCui(): void
    {
        $debtor = new Debtor();
        $debtor->setCui(' ro 0123 ');
        $this->assertSame('123', $debtor->getCuiKey());

        $debtor->setCui(null);
        $this->assertNull($debtor->getCuiKey());
    }

    public function testTheLinkReadsIdentityFromTheCompanyAndKeepsItsOwnChecks(): void
    {
        $debtor = new Debtor();
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('Debitor SRL');
        $debtor->setCui('RO87654321');
        $debtor->setAddress('Str. Datoriei nr. 5');

        $link = new LegalCaseDebtor($debtor);
        $proof = new Document();
        $anafChecked = new \DateTimeImmutable('2026-05-09 10:00:00');
        $bpiChecked = new \DateTimeImmutable('2026-05-09 10:30:00');
        $link->setAnafStatus(AnafStatus::ACTIV);
        $link->setAnafCheckedAt($anafChecked);
        $link->setInsolvencyCheckedAt($bpiChecked);
        $link->setBpiProofDocument($proof);
        $link->setBpiVerifiedNote('Verificat 2026-05-09, fără mențiuni.');

        $this->assertSame($debtor, $link->getDebtor());
        $this->assertSame('Debitor SRL', $link->getName());
        $this->assertSame('RO87654321', $link->getCui());
        $this->assertSame(PersonType::PJ, $link->getPersonType());
        $this->assertSame(AnafStatus::ACTIV, $link->getAnafStatus());
        $this->assertSame($anafChecked, $link->getAnafCheckedAt());
        $this->assertSame($bpiChecked, $link->getInsolvencyCheckedAt());
        $this->assertFalse($link->isInInsolvency());
        $this->assertSame($proof, $link->getBpiProofDocument());
        $this->assertSame('Debitor SRL', (string) $link);
    }

    public function testChecksDoNotTravelBetweenCasesOfTheSameCompany(): void
    {
        $debtor = new Debtor();
        $debtor->setName('Debitor SRL');

        $first = new LegalCaseDebtor($debtor);
        $first->setInsolvencyCheckedAt(new \DateTimeImmutable('2026-05-09'));
        $first->setAnafStatus(AnafStatus::ACTIV);
        $second = new LegalCaseDebtor($debtor);

        $this->assertNull($second->getInsolvencyCheckedAt());
        $this->assertNull($second->getAnafStatus());
    }

    public function testTheCaseKeepsTheOrderOfItsDebtors(): void
    {
        $case = new LegalCase();
        $first = new LegalCaseDebtor((new Debtor())->setName('Unu SRL'));
        $second = new LegalCaseDebtor((new Debtor())->setName('Doi SRL'));
        $case->addDebtor($first);
        $case->addDebtor($second);

        $this->assertSame(0, $first->getPosition());
        $this->assertSame(1, $second->getPosition());
        $this->assertSame($case, $first->getLegalCase());
        $this->assertSame($first, $case->getPrimaryDebtor());
    }
}
