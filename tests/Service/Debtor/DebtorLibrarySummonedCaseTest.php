<?php

declare(strict_types=1);

namespace App\Tests\Service\Debtor;

use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\PersonType;
use App\Service\Debtor\DebtorLibraryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DebtorLibrarySummonedCaseTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->user = new User();
        $this->user->setEmail('debtor-summoned-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $id = $this->user->getId();
        $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM debtor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $id]);
        parent::tearDown();
    }

    private function debtorOnCase(CaseStatus $status, ?string $fullPaymentDate, ?string $paymentNoticeDate): Debtor
    {
        $debtor = new Debtor();
        $debtor->setUser($this->user);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Plătitor SRL');
        $debtor->setAddress('Str. Test 1');
        $debtor->setCui('RO15193236');
        $this->em->persist($debtor);

        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus($status);
        $case->setFullPaymentDate($fullPaymentDate !== null ? new \DateTimeImmutable($fullPaymentDate) : null);
        $case->setPaymentNoticeDate($paymentNoticeDate !== null ? new \DateTime($paymentNoticeDate) : null);
        $case->addDebtor(new LegalCaseDebtor($debtor));
        $this->em->persist($case);
        $this->em->flush();

        $id = $debtor->getId();
        $this->em->clear();

        return $this->em->getRepository(Debtor::class)->find($id);
    }

    public function testACaseClosedOnFullPaymentFromAmiabilDidNotSummonTheDebtor(): void
    {
        $debtor = $this->debtorOnCase(CaseStatus::INCHIS_SUCCES, '2026-01-10', null);

        self::assertFalse(static::getContainer()->get(DebtorLibraryService::class)->hasSummonedCase($debtor));
    }

    public function testACaseClosedOnFullPaymentAfterTheSomatieSummonedTheDebtor(): void
    {
        $debtor = $this->debtorOnCase(CaseStatus::INCHIS_SUCCES, '2026-01-10', '2026-01-02');

        self::assertTrue(static::getContainer()->get(DebtorLibraryService::class)->hasSummonedCase($debtor));
    }
}
