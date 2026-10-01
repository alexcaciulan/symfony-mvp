<?php

declare(strict_types=1);

namespace App\Tests\Service\Creditor;

use App\DTO\Wizard\Step1CreditorData;
use App\Entity\AuditLog;
use App\Entity\Creditor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\PersonType;
use App\Service\Creditor\CreditorLibraryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreditorLibraryServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CreditorLibraryService $library;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->library = static::getContainer()->get(CreditorLibraryService::class);
        $this->user = new User();
        $this->user->setEmail('creditor-lib-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $id = $this->user->getId();
        $conn->executeStatement('DELETE FROM audit_log WHERE entity_type = :t AND entity_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['t' => LegalCase::class, 'id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $id]);
        parent::tearDown();
    }

    public function testCompletingFillsEmptyFieldsAndTakesCountyOnlyWithItsAddress(): void
    {
        $creditor = $this->creditor();
        $case = $this->caseFor($creditor, CaseStatus::AMIABIL);

        $this->library->completeEmpty($creditor, new Step1CreditorData(
            name: 'Alt Nume SRL',
            address: 'Str. Alta 9',
            addressCounty: 'Cluj',
            addressLocality: 'Cluj-Napoca',
            iban: 'RO49AAAA1B31007593840000',
        ));
        $this->em->flush();

        self::assertSame('Acme SRL', $creditor->getName(), 'a filled field stays');
        self::assertSame('RO49AAAA1B31007593840000', $creditor->getIban());
        self::assertNull($creditor->getAddressCounty(), 'the county of another address would move the stamp duty');
        $entry = $this->em->getRepository(AuditLog::class)->findOneBy(['action' => 'creditor_identity_changed', 'entityId' => (string) $case->getId()]);
        self::assertSame(['iban' => 'RO49AAAA1B31007593840000'], $entry->getNewData());
    }

    public function testTheCuiMovesOnlyBeforeASomatieWentOut(): void
    {
        $creditor = $this->creditor();
        self::assertFalse($this->library->cuiChangeRefused($creditor, 'RO14186770'));
        self::assertFalse($this->library->cuiChangeRefused($creditor, '15193236'), 'another spelling is the same CUI');

        $this->caseFor($creditor, CaseStatus::SOMATIE_TRIMISA);

        self::assertTrue($this->library->cuiChangeRefused($creditor, 'RO14186770'));
    }

    /** A case closed on full payment straight from AMIABIL sent no somatie, so the CUI may still move. */
    public function testACaseClosedOnFullPaymentBeforeTheSomatieDoesNotLockTheCui(): void
    {
        $creditor = $this->creditor();
        $case = $this->caseFor($creditor, CaseStatus::INCHIS_SUCCES);
        $case->setFullPaymentDate(new \DateTimeImmutable('2026-01-10'));
        $this->em->flush();

        self::assertFalse($this->library->cuiChangeRefused($creditor, 'RO14186770'));

        $case->setPaymentNoticeDate(new \DateTime('2026-01-02'));
        $this->em->flush();

        self::assertTrue($this->library->cuiChangeRefused($creditor, 'RO14186770'), 'Closed after the somatie went out: the CUI is locked.');
    }

    private function creditor(): Creditor
    {
        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('Acme SRL');
        $creditor->setCui('RO15193236');
        $creditor->setAddress('Str. Acme 1');
        $this->em->persist($creditor);
        $this->em->flush();

        return $creditor;
    }

    private function caseFor(Creditor $creditor, CaseStatus $status): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCreditor($creditor);
        $case->setStatus($status);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }
}
