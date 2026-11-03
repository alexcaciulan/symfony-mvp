<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\AuditLog;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\NotificationChannel;
use App\Enum\PersonType;
use App\Service\AuditLogService;
use App\Service\Case\CaseDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CaseDeletionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CaseDeletionService $service;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(CaseDeletionService::class);

        $this->user = new User();
        $this->user->setEmail('case-deletion-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $id = $this->user->getId();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM notification WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM debtor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $id]);
        parent::tearDown();
    }

    private function makeCase(CaseStatus $status): LegalCase
    {
        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Deletion Creditor SRL');
        $creditor->setAddress('Str. Test 1, București');
        $creditor->setCui('RO12345678');
        $this->em->persist($creditor);

        $debtor = new Debtor();
        $debtor->setUser($this->user);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Deletion Debtor SRL');
        $debtor->setAddress('Str. Test 2, București');
        $debtor->setCui('RO87654321');
        $this->em->persist($debtor);

        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus($status);
        $case->setCreditor($creditor);
        $case->addDebtor(new LegalCaseDebtor($debtor));
        $case->setAmount('4200.00');
        $case->setCurrency('RON');
        $this->em->persist($case);

        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename('factura-17.pdf');
        $document->setStoredFilename('stored-' . uniqid() . '.pdf');
        $document->setFileSize(1024);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($this->user);
        $this->em->persist($document);

        $this->em->flush();

        return $case;
    }

    private function makeNotification(LegalCase $case): Notification
    {
        $notification = (new Notification())
            ->setUser($this->user)
            ->setLegalCase($case)
            ->setType('deadline_alert')
            ->setChannel(NotificationChannel::IN_APP)
            ->setTitle('Termen pe dosar')
            ->setMessage('Corp mesaj')
            ->setResourceLink('/case/' . $case->getId());
        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }

    public function testDeleteMarksTheCaseReadsItsNoticesAndWritesTheSnapshot(): void
    {
        $case = $this->makeCase(CaseStatus::AMIABIL);
        $case->setPaymentNoticeDate(new \DateTime('2026-10-20'));
        $this->em->flush();
        $notification = $this->makeNotification($case);

        $this->service->delete($case, $this->user, 'Creanța a fost achitată înainte de somație.');

        self::assertTrue($case->isDeleted());
        self::assertNotNull($case->getDeletedAt());

        $this->em->refresh($notification);
        self::assertTrue($notification->isRead());
        self::assertNotNull($notification->getReadAt());

        $entry = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
            'action' => CaseDeletionService::ACTION,
        ]);
        self::assertInstanceOf(AuditLog::class, $entry);
        self::assertSame(AuditLogService::CATEGORY_CASE_DELETED, $entry->getCategory());
        self::assertSame($this->user->getId(), $entry->getUser()?->getId());

        $snapshot = $entry->getNewData();
        self::assertSame($case->getCaseNumber(), $snapshot['caseNumber']);
        self::assertSame('AMIABIL', $snapshot['status']);
        self::assertSame('SC Deletion Creditor SRL', $snapshot['creditor']['name']);
        self::assertSame('SC Deletion Debtor SRL', $snapshot['debtors'][0]['name']);
        self::assertSame('4200.00', $snapshot['amount']);
        self::assertSame(1, $snapshot['documentCount']);
        self::assertSame('factura-17.pdf', $snapshot['documents'][0]['filename']);
        self::assertSame('2026-10-20', $snapshot['paymentNoticeDate']);
        self::assertSame('Creanța a fost achitată înainte de somație.', $snapshot['reason']);
    }

    /**
     * The audit entry outlives the case in the lists, so a natural person's CNP
     * must not travel into it in clear.
     */
    public function testTheSnapshotHoldsNoPersonalNumericCodeInClear(): void
    {
        $case = $this->makeCase(CaseStatus::AMIABIL);
        $cnp = '1980715221232';
        $person = new Debtor();
        $person->setUser($this->user);
        $person->setPersonType(PersonType::PF);
        $person->setName('Ion Popescu');
        $person->setAddress('Str. Test 3, București');
        $person->setPersonalId($cnp);
        $this->em->persist($person);
        $case->addDebtor(new LegalCaseDebtor($person));
        $this->em->flush();

        $this->service->delete($case, $this->user, 'CNP ' . $cnp . ' dat din greșeală');

        $entry = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityId' => (string) $case->getId(),
            'action' => CaseDeletionService::ACTION,
        ]);
        self::assertInstanceOf(AuditLog::class, $entry);
        $snapshot = $entry->getNewData();
        self::assertCount(2, $snapshot['debtors']);
        self::assertSame('Ion Popescu', $snapshot['debtors'][1]['name']);
        self::assertStringNotContainsString($cnp, json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertSame('CNP ***-***-1232 dat din greșeală', $snapshot['reason']);
    }

    public function testTheReasonIsOptional(): void
    {
        $case = $this->makeCase(CaseStatus::AMIABIL);

        $this->service->delete($case, $this->user, null);

        $entry = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityId' => (string) $case->getId(),
            'action' => CaseDeletionService::ACTION,
        ]);
        self::assertInstanceOf(AuditLog::class, $entry);
        self::assertNull($entry->getNewData()['reason']);
    }

    public function testRefusesOnceTheSummonsWentOut(): void
    {
        $case = $this->makeCase(CaseStatus::SOMATIE_TRIMISA);

        $this->expectException(\DomainException::class);
        try {
            $this->service->delete($case, $this->user, null);
        } finally {
            self::assertFalse($case->isDeleted());
            self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM audit_log WHERE entity_id = :id AND action = :action',
                ['id' => (string) $case->getId(), 'action' => CaseDeletionService::ACTION],
            ));
        }
    }

    public function testRefusesACaseAlreadyDeleted(): void
    {
        $case = $this->makeCase(CaseStatus::AMIABIL);
        $case->markAsDeleted();
        $this->em->flush();
        $firstDeletedAt = $case->getDeletedAt();

        $this->expectException(\DomainException::class);
        try {
            $this->service->delete($case, $this->user, null);
        } finally {
            self::assertSame($firstDeletedAt, $case->getDeletedAt());
        }
    }

    /**
     * The status is read again under the row lock: a summons generated from another
     * tab between the dialog and the confirmation must stop the deletion.
     */
    public function testTheStatusIsReadAgainUnderTheLock(): void
    {
        $case = $this->makeCase(CaseStatus::AMIABIL);
        $this->em->getConnection()->executeStatement(
            'UPDATE legal_case SET status = :status WHERE id = :id',
            ['status' => CaseStatus::SOMATIE_TRIMISA->value, 'id' => $case->getId()],
        );

        try {
            $this->service->delete($case, $this->user, null);
            self::fail('The deletion must be refused.');
        } catch (\DomainException) {
        }

        self::assertNull($this->em->getConnection()->fetchOne(
            'SELECT deleted_at FROM legal_case WHERE id = :id',
            ['id' => $case->getId()],
        ));
    }
}
