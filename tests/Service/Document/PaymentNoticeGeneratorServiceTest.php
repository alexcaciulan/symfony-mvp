<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\PenaltyType;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\Document\PaymentNoticeGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 5.1 — Tests for PaymentNoticeGeneratorService.
 *
 * Critical legal assertion: rendered HTML must contain "15 zile" verbatim
 * (CPC art. 1015 alin. 1) and must NOT contain "30 zile" (which would confuse
 * the CPC notice deadline with the Legea 72/2013 art. 3 alin. 1 contractual
 * deadline; the two are distinct and only the 15-day deadline triggers the
 * payment-order petition).
 */
final class PaymentNoticeGeneratorServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PaymentNoticeGeneratorService $service;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(PaymentNoticeGeneratorService::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('summons-gen-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Avocat');
        $this->user->setLastName('Test');
        $this->user->setBarNumber('B-12345');
        $this->em->persist($this->user);

        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Test Creditor SRL');
        $creditor->setAddress('Str. Test 1, București');
        $creditor->setCui('RO99999111');
        $creditor->setIban('RO49AAAA1B31007593840000');
        $creditor->setBankName('Banca Transilvania');
        $this->em->persist($creditor);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setAmount('5000.00');
        $this->case->setCurrency('RON');
        $this->case->setRelationshipType(RelationshipType::COMERCIAL);
        $this->case->setCalculatedInterest('312.50');
        $this->em->persist($this->case);

        $debtor = new Debtor();
        $debtor->setLegalCase($this->case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Test Debtor SRL');
        $debtor->setAddress('Str. Test 2, București');
        $debtor->setCui('RO88888222');
        $this->em->persist($debtor);
        $this->case->addDebtor($debtor);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        parent::tearDown();
    }

    public function testRenderHtmlContains15DaysVerbatim(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('15 zile', $html, 'Somația trebuie să conțină termenul legal de 15 zile (CPC art. 1015 alin. 1).');
    }

    public function testRenderHtmlContainsSomatieDePlataHeading(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('SOMAȚIE DE PLATĂ', $html, 'Cerință PLAN Pas 5.1: heading-ul „SOMAȚIE DE PLATĂ" trebuie să apară în PDF.');
    }

    public function testRenderHtmlDoesNotContain30Days(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('30 zile', $html, 'Somația NU trebuie să conțină termenul de 30 zile (acela e Legea 72/2013 contractual, distinct de CPC).');
    }

    public function testRenderHtmlContainsCreditorAndDebtorNames(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('SC Test Creditor SRL', $html);
        self::assertStringContainsString('SC Test Debtor SRL', $html);
        self::assertStringContainsString('5.000,00', $html, 'Suma principală trebuie formatată cu separator de mii (5.000,00).');
        self::assertStringContainsString('1015', $html, 'Temeiul legal CPC art. 1015 trebuie citat.');
    }

    public function testGeneratePersistsDocumentWithTypeSomatie(): void
    {
        $document = $this->service->generate($this->case);
        $this->em->flush();

        self::assertInstanceOf(Document::class, $document);
        self::assertSame(DocumentType::SOMATIE, $document->getDocumentType());
        self::assertSame($this->case, $document->getLegalCase());
        self::assertGreaterThan(0, $document->getFileSize());
        self::assertSame('application/pdf', $document->getMimeType());
        self::assertStringContainsString($this->case->getCaseNumber(), $document->getOriginalFilename());
        self::assertNotNull($document->getId(), 'Document should be persisted with an ID after flush.');
    }

    public function testRenderHtmlShowsClaimObjectWhenDescriptionSet(): void
    {
        $this->case->setClaimDescription('Contravaloare marfă livrată neachitată');
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('Contravaloare marfă livrată neachitată', $html);
    }

    public function testRenderHtmlHandlesNullInterestWithoutCrashing(): void
    {
        $this->case->setCalculatedInterest(null);
        $this->case->setDueDate(null);
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('15 zile', $html);
        self::assertStringContainsString('5.000,00', $html, 'Cu interest=null, totalul rămâne principalul (fără crash pe + null).');
    }

    public function testRenderHtmlContainsAllStructuralSections(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('Către', $html, 'Antetul „Către" (debitor) trebuie prezent.');
        self::assertStringContainsString('De la', $html, 'Antetul „De la" (creditor) trebuie prezent.');
        self::assertStringContainsString('* * *', $html, 'Separatorul „* * *" trebuie prezent.');
        self::assertStringContainsString('1013', $html, 'Temeiul CPC art. 1013 (procedura OP) trebuie citat.');
        self::assertStringContainsString('1522', $html, 'Temeiul CC art. 1522 (punere în întârziere) trebuie citat.');
        self::assertStringContainsString('Banca Transilvania', $html, 'Banca creditorului trebuie inclusă în identificare.');
    }

    public function testLegalPenaltyRendersInterestBreakdownTable(): void
    {
        // Test-DB rate fixtures: 6,50% valid from 2025-01-01 (no change until 2025-08-01),
        // so the period 2025-01-01..2025-04-01 is a single 14,50% (BNR 6,5 + 8) block.
        $this->case->setPenaltyType(PenaltyType::LEGAL_PENALIZATOARE);
        $this->case->setAmount('100000.00');
        $this->case->setDueDate(new \DateTime('2025-01-01'));
        $this->case->setPaymentNoticeDate(new \DateTime('2025-04-01')); // 90 days, rate 14.5%
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        // 100000 * 14.5% * 90 / 365 = 3575.34
        self::assertStringContainsString('Nr. zile', $html, 'Tabelul de dobândă trebuie să aibă coloana „Nr. zile".');
        self::assertStringContainsString('14,50%', $html, 'Rata aplicabilă (BNR 6,5 + 8 = 14,5%) trebuie afișată.');
        self::assertStringContainsString('3.575,34', $html, 'Totalul dobânzii legale trebuie calculat corect.');
        self::assertStringContainsString('OG 13/2011', $html, 'Temeiul OG 13/2011 trebuie citat pe ramura legală.');
    }

    public function testContractualPenaltyRendersDailyRateLine(): void
    {
        $this->case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $this->case->setAmount('175525.00');
        $this->case->setContractualPenaltyRate('0.100');
        $this->case->setContractReference('art. 3 din Contract');
        $due = new \DateTime('2025-02-18');
        $this->case->setDueDate($due);
        $this->case->setPaymentNoticeDate((clone $due)->modify('+51 days'));
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('8.951,78', $html, 'Penalitatea contractuală (0,10%/zi x 51 zile) trebuie să fie 8.951,78.');
        self::assertStringContainsString('art. 1538', $html, 'Clauza penală trebuie citată (CC art. 1538).');
        self::assertStringContainsString('art. 3 din Contract', $html, 'Referința contractuală trebuie afișată.');
        self::assertStringNotContainsString('OG 13/2011', $html, 'Ramura contractuală NU trebuie să citeze OG 13/2011.');
    }

    public function testContractualPenaltyRendersLegalCostsSection(): void
    {
        $this->case->setPenaltyType(PenaltyType::CONTRACTUAL);
        $this->case->setContractualPenaltyRate('0.100');
        $this->case->setDueDate(new \DateTime('2025-02-18'));
        $this->case->setPaymentNoticeDate(new \DateTime('2025-04-10'));
        $this->case->setLegalCostsFixed('250.00');
        $this->case->setLegalCostsCurrency('EUR');
        $this->case->setLegalCostsSuccessPercent('5.00');
        $this->em->flush();

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('1531', $html, 'Secțiunea cheltuieli trebuie să citeze CC art. 1531.');
        self::assertStringContainsString('250,00 EUR', $html, 'Onorariul fix trebuie afișat.');
        self::assertStringContainsString('5,00%', $html, 'Onorariul de succes (procent) trebuie afișat.');
    }
}
