<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\Entity\AuditLog;
use App\Entity\Creditor;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\AuditLogService;
use App\Service\Case\CaseNotesService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CaseNotesServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CaseNotesService $service;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(CaseNotesService::class);

        $this->user = new User();
        $this->user->setEmail('case-notes-' . uniqid() . '@test.com');
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
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $id]);
        parent::tearDown();
    }

    public function testNotesAreSavedTrimmedWithAudit(): void
    {
        $case = $this->makeCase(CaseStatus::AMIABIL);
        $case->setNotes('Prima discuție');
        $this->em->flush();

        self::assertTrue($this->service->update($case, "  Clientul promite plata pe 15.  \n", $this->user));

        $this->em->clear();
        self::assertSame('Clientul promite plata pe 15.', $this->em->find(LegalCase::class, $case->getId())->getNotes());
        $entries = $this->audits($case);
        self::assertCount(1, $entries);
        self::assertSame(AuditLogService::CATEGORY_CASE_NOTES, $entries[0]->getCategory());
        self::assertSame(['notesLength' => mb_strlen('Prima discuție'), 'notesSha256' => hash('sha256', 'Prima discuție')], $entries[0]->getOldData());
        self::assertSame(mb_strlen('Clientul promite plata pe 15.'), $entries[0]->getNewData()['notesLength']);
        self::assertSame(hash('sha256', 'Clientul promite plata pe 15.'), $entries[0]->getNewData()['notesSha256']);
        self::assertArrayNotHasKey('notes', $entries[0]->getNewData());
        self::assertSame($this->user->getId(), $entries[0]->getUser()?->getId());
    }

    public function testNotesTextNeverReachesAudit(): void
    {
        $cnp = '1980715221232';
        $case = $this->makeCase(CaseStatus::AMIABIL);

        $this->service->update($case, 'Administrator CNP ' . $cnp, $this->user);

        self::assertSame('Administrator CNP ' . $cnp, $case->getNotes(), 'The case keeps the text as typed.');
        $entry = $this->audits($case)[0];
        self::assertStringNotContainsString($cnp, json_encode($entry->getNewData(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Administrator', json_encode($entry->getNewData(), JSON_THROW_ON_ERROR));
    }

    public function testUnchangedNotesWriteNoAudit(): void
    {
        $case = $this->makeCase(CaseStatus::AMIABIL);
        $case->setNotes('Text');
        $this->em->flush();

        self::assertFalse($this->service->update($case, ' Text ', $this->user));
        self::assertSame([], $this->audits($case));
    }

    public function testEmptyNotesClearTheField(): void
    {
        $case = $this->makeCase(CaseStatus::AMIABIL);
        $case->setNotes('Text');
        $this->em->flush();

        self::assertTrue($this->service->update($case, '   ', $this->user));

        $this->em->clear();
        self::assertNull($this->em->find(LegalCase::class, $case->getId())->getNotes());
    }

    public function testNotesAreSavedInATerminalStatus(): void
    {
        $case = $this->makeCase(CaseStatus::INCHIS_FARA_RECUPERARE);

        self::assertTrue($this->service->update($case, 'Debitor radiat.', $this->user));

        $this->em->clear();
        self::assertSame('Debitor radiat.', $this->em->find(LegalCase::class, $case->getId())->getNotes());
    }

    private function makeCase(CaseStatus $status): LegalCase
    {
        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Notes Creditor SRL');
        $creditor->setAddress('Str. Notes 1, Cluj-Napoca');
        $creditor->setCui('RO12345678');
        $this->em->persist($creditor);

        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCreditor($creditor);
        $case->setStatus($status);
        $case->setRelationshipType(RelationshipType::COMERCIAL);
        $case->setAmount('1000.00');
        $case->setCurrency('RON');
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    /** @return list<AuditLog> */
    private function audits(LegalCase $case): array
    {
        return $this->em->getRepository(AuditLog::class)->findBy(
            ['entityId' => (string) $case->getId(), 'action' => CaseNotesService::ACTION],
            ['id' => 'ASC'],
        );
    }
}
