<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\CaseStatusHistory;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineBlockageReason;
use App\Enum\DeadlineType;
use App\Enum\DocumentType;
use App\Enum\StampDutyStatus;
use App\Service\Deadline\DeadlineBlockage;
use App\Service\Deadline\DeadlineBlockageFinder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The blocked cases behind the blockage zone: the fatal terms missing from the agenda
 * because the fact they run from carries no date, plus the filing held back for want of
 * the proof of communication. Each reason is pinned together with the state that must
 * NOT raise it, since a false blockage sends the lawyer after something that changes
 * nothing.
 */
class DeadlineBlockageFinderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DeadlineBlockageFinder $finder;
    private User $user;
    private User $otherUser;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->finder = static::getContainer()->get(DeadlineBlockageFinder::class);
        $this->testPrefix = 'deadline-blockage-' . uniqid();

        $this->user = $this->createUser('owner');
        $this->otherUser = $this->createUser('other');
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $ids = [$this->user->getId(), $this->otherUser->getId()];
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        $conn->executeStatement(
            'DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id IN (?))',
            [$ids],
            [ArrayParameterType::INTEGER],
        );
        $conn->executeStatement(
            'DELETE FROM case_status_history WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id IN (?))',
            [$ids],
            [ArrayParameterType::INTEGER],
        );
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        $conn->executeStatement('DELETE FROM `user` WHERE id IN (?)', [$ids], [ArrayParameterType::INTEGER]);

        parent::tearDown();
    }

    public function testSummonsWithoutCommunicationDateIsBlocked(): void
    {
        $case = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeDate(new \DateTime('-5 days'));
        $this->em->flush();

        self::assertSame(
            [DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING],
            $this->reasonsFor($case),
        );
    }

    /** The summons is generated while the case is still amiable, so the gap opens there. */
    public function testSummonsSentDuringTheAmiableStageIsAlreadyBlocked(): void
    {
        $case = $this->createCase(CaseStatus::AMIABIL);
        $case->setPaymentNoticeDate(new \DateTime('-2 days'));
        $this->em->flush();

        self::assertSame(
            [DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING],
            $this->reasonsFor($case),
        );
    }

    /** No summons generated yet: there is no fact whose date could be missing. */
    public function testCaseWithoutAGeneratedSummonsIsNotBlocked(): void
    {
        $case = $this->createCase(CaseStatus::AMIABIL);
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /**
     * The date closes the first gap and opens the second: the 15-day term now exists,
     * and what is missing is the document the filing rests on. The lawyer is asked for
     * one thing at a time, which is also what keeps a case at one blockage.
     */
    public function testSummonsWithTheCommunicationDateIsAskedForTheProofInstead(): void
    {
        $case = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeDate(new \DateTime('-5 days'));
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-3 days'));
        $this->em->flush();

        self::assertSame(
            [DeadlineBlockageReason::SUMMONS_PROOF_MISSING],
            $this->reasonsFor($case),
        );
    }

    public function testSummonsWithTheDateAndTheProofIsNotBlocked(): void
    {
        $case = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeDate(new \DateTime('-5 days'));
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-3 days'));
        $this->createDocument($case, DocumentType::DOVADA_COMUNICARE);
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /**
     * Another document on the case is not the proof. The blockage keys off the type,
     * not off the case having attachments, because the filing gate does the same.
     */
    public function testAnotherAttachmentDoesNotPassForTheProof(): void
    {
        $case = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeDate(new \DateTime('-5 days'));
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-3 days'));
        $this->createDocument($case, DocumentType::FACTURA);
        $this->em->flush();

        self::assertSame(
            [DeadlineBlockageReason::SUMMONS_PROOF_MISSING],
            $this->reasonsFor($case),
        );
    }

    /**
     * Past filing the row would unblock nothing the lawyer can still act on, exactly as
     * for the missing date: the petition is out, with or without the proof in the file.
     */
    public function testTheProofBlockageStopsOnceTheRequestIsFiled(): void
    {
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);
        $case->setPaymentNoticeDate(new \DateTime('-20 days'));
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-18 days'));
        $case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /** Past filing the receipt date no longer unblocks anything, so it stops being a blockage. */
    public function testSummonsBlockageStopsOnceTheRequestIsFiled(): void
    {
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);
        $case->setPaymentNoticeDate(new \DateTime('-20 days'));
        $case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    public function testIssuedOrderWithoutTheRulingCommunicationDateIsBlocked(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $this->em->flush();

        self::assertSame(
            [DeadlineBlockageReason::RULING_COMMUNICATION_MISSING],
            $this->reasonsFor($case),
        );
    }

    public function testIssuedOrderWithTheRulingCommunicationDateIsNotBlocked(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('-2 days'));
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /** In IN_ANULARE the annulment request is already filed: the term is spent. */
    public function testCaseAlreadyInAnnulmentIsNotBlockedOnTheRulingDate(): void
    {
        $case = $this->createCase(CaseStatus::IN_ANULARE);
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    public function testFiledUnstampedCaseWithoutTheStampDutyDeadlineIsBlocked(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        $this->em->flush();

        self::assertSame(
            [DeadlineBlockageReason::STAMP_DUTY_NOTICE_MISSING],
            $this->reasonsFor($case),
        );
    }

    /** The deadline exists only when the court notice was recorded, so it clears the blockage. */
    public function testRecordedStampDutyNoticeClearsTheBlockage(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->createDeadline($case, DeadlineType::TIMBRARE);
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /** No regularization notice is ever issued for a stamped claim. */
    public function testPaidStampDutyIsNotBlocked(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $case->setStampDutyStatus(StampDutyStatus::ACHITATA);
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /** The court sets the term at the first hearing too, so the gap survives that far. */
    public function testUnstampedCaseWithAHearingSetIsStillBlocked(): void
    {
        $case = $this->createCase(CaseStatus::TERMEN_FIXAT);
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();

        self::assertSame(
            [DeadlineBlockageReason::STAMP_DUTY_NOTICE_MISSING],
            $this->reasonsFor($case),
        );
    }

    /** Before filing there is no court to issue a regularization notice. */
    public function testUnstampedCaseThatIsNotFiledYetIsNotBlocked(): void
    {
        $case = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $case->setStampDutyStatus(StampDutyStatus::NEACHITATA);
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /**
     * The risk bar counts CASES here, not deadlines, which only holds because the reasons
     * stay mutually exclusive: one case, at most one row. Most of them are separated by
     * status; the two on the summons share theirs and are separated by the communication
     * date, which is why both appear below on cases of the same status. The order is the
     * one the zone renders in, worst gap first.
     */
    public function testEachCaseRaisesAtMostOneBlockageAndTheListIsOrderedBySeverity(): void
    {
        $summons = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $summons->setPaymentNoticeDate(new \DateTime('-5 days'));

        $proof = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $proof->setPaymentNoticeDate(new \DateTime('-4 days'));
        $proof->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-2 days'));

        $ruling = $this->createCase(CaseStatus::ORDONANTA_EMISA);

        $stamping = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $stamping->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);

        // Final after an annulment request: the anchor is the second ruling, so this
        // case belongs to the annulment reason and not to the plain enforcement one,
        // which is what keeps the two disjoint on the same status.
        $annulment = $this->createCase(CaseStatus::DEFINITIVA);
        $this->recordAnnulmentPassage($annulment);

        $enforcement = $this->createCase(CaseStatus::DEFINITIVA);

        // EXECUTARE is the only status the registration-number reason applies to, which
        // is what keeps it disjoint from the ones above.
        $registration = $this->createCase(CaseStatus::EXECUTARE);
        $registration->setEnforcementRequestDate(new \DateTimeImmutable('-3 days'));
        $this->em->flush();

        $blockages = $this->finder->find($this->user);

        self::assertSame(
            [
                DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING,
                DeadlineBlockageReason::SUMMONS_PROOF_MISSING,
                DeadlineBlockageReason::RULING_COMMUNICATION_MISSING,
                DeadlineBlockageReason::STAMP_DUTY_NOTICE_MISSING,
                DeadlineBlockageReason::ANNULMENT_RULING_COMMUNICATION_MISSING,
                DeadlineBlockageReason::EXECUTION_ANCHOR_MISSING,
                DeadlineBlockageReason::ENFORCEMENT_REGISTRATION_NUMBER_MISSING,
            ],
            array_map(static fn (DeadlineBlockage $b): DeadlineBlockageReason => $b->reason, $blockages),
        );

        $caseIds = array_map(static fn (DeadlineBlockage $b): ?int => $b->legalCase->getId(), $blockages);
        self::assertSame([$summons->getId(), $proof->getId(), $ruling->getId(), $stamping->getId(), $annulment->getId(), $enforcement->getId(), $registration->getId()], $caseIds);
        self::assertSame($caseIds, array_values(array_unique($caseIds)), 'One case may never raise two blockages.');
    }

    public function testSoftDeletedAndForeignCasesNeverAppear(): void
    {
        $deleted = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $deleted->setDeletedAt(new \DateTimeImmutable());

        $foreign = $this->createCase(CaseStatus::ORDONANTA_EMISA, $this->otherUser);
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($deleted));

        $ownIds = array_map(
            static fn (DeadlineBlockage $b): ?int => $b->legalCase->getId(),
            $this->finder->find($this->user),
        );
        self::assertNotContains($foreign->getId(), $ownIds, 'A case of another account must never reach the list.');
    }

    /**
     * With neither the communication date nor the ruling date, the enforcement
     * limitation term is deliberately not created, so nothing but this zone tells the
     * lawyer that a three-year term is running unwatched.
     */
    public function testFinalCaseWithNoAnchorForTheEnforcementLimitationIsBlocked(): void
    {
        $case = $this->createCase(CaseStatus::DEFINITIVA);
        $this->em->flush();

        self::assertSame(
            [DeadlineBlockageReason::EXECUTION_ANCHOR_MISSING],
            $this->reasonsFor($case),
        );
    }

    /** Enforcement can start without passing through DEFINITIVA, so the gap follows the case there. */
    /**
     * Enforcement having started closes the enforcement-limitation term (the request to
     * the bailiff interrupts it, CPC art. 708 para. 1 pt. 2), so asking for its anchor
     * there would be asking for the starting date of a term that no longer runs.
     */
    public function testEnforcedCaseIsNotAskedForAnAnchorItNoLongerNeeds(): void
    {
        $case = $this->createCase(CaseStatus::EXECUTARE);
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /**
     * The filing with the bailiff is declared and the number under which he registered it
     * is missing. The term is not closed on the declaration alone, so the case waits, and
     * the wait is what the zone states: this is the passive surface that replaces the
     * repeated reminders the platform deliberately does not send.
     */
    public function testEnforcedCaseWithoutTheBailiffRegistrationNumberIsBlocked(): void
    {
        $case = $this->createCase(CaseStatus::EXECUTARE);
        $case->setEnforcementRequestDate(new \DateTimeImmutable('-3 days'));
        $this->em->flush();

        self::assertSame(
            [DeadlineBlockageReason::ENFORCEMENT_REGISTRATION_NUMBER_MISSING],
            $this->reasonsFor($case),
        );
    }

    public function testTheRecordedRegistrationNumberClearsThatBlockage(): void
    {
        $case = $this->createCase(CaseStatus::EXECUTARE);
        $case->setEnforcementRequestDate(new \DateTimeImmutable('-3 days'));
        $case->setEnforcementRegistrationNumber('412/2026');
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /**
     * A case that went through an annulment request became final through the ruling on
     * it (CPC art. 1024 para. 8), so what is missing is the service date of THAT
     * ruling, not the one of the initial order.
     */
    public function testCaseThatWentThroughAnnulmentAsksForTheSecondRulingDate(): void
    {
        $case = $this->createCase(CaseStatus::DEFINITIVA);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('-90 days'));
        $this->recordAnnulmentPassage($case);
        $this->em->flush();

        self::assertSame(
            [DeadlineBlockageReason::ANNULMENT_RULING_COMMUNICATION_MISSING],
            $this->reasonsFor($case),
            'The communication of the first order does not answer when the title became final.',
        );
    }

    public function testAnnulmentRulingCommunicationDateClearsThatBlockage(): void
    {
        $case = $this->createCase(CaseStatus::DEFINITIVA);
        $this->recordAnnulmentPassage($case);
        $case->setAnnulmentRulingCommunicationDate(new \DateTimeImmutable('-20 days'));
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /** The ruling date is the conservative fallback anchor, so having it clears the gap. */
    public function testRulingDateAloneClearsTheEnforcementAnchorBlockage(): void
    {
        $case = $this->createCase(CaseStatus::DEFINITIVA);
        $case->setFinalRulingDate(new \DateTime('-40 days'));
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    public function testRulingCommunicationDateClearsTheEnforcementAnchorBlockage(): void
    {
        $case = $this->createCase(CaseStatus::DEFINITIVA);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('-30 days'));
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /** The term exists already, so asking for a date it was computed from changes nothing. */
    public function testExistingEnforcementLimitationDeadlineClearsTheBlockage(): void
    {
        $case = $this->createCase(CaseStatus::DEFINITIVA);
        $this->createDeadline($case, DeadlineType::PRESCRIPTIE_EXECUTARE);
        $this->em->flush();

        self::assertSame([], $this->reasonsFor($case));
    }

    /** @return list<DeadlineBlockageReason> */
    private function reasonsFor(LegalCase $case): array
    {
        return array_map(
            static fn (DeadlineBlockage $b): DeadlineBlockageReason => $b->reason,
            $this->blockagesFor($case),
        );
    }

    /** @return list<DeadlineBlockage> */
    private function blockagesFor(LegalCase $case): array
    {
        $owner = $case->getUser();

        return array_values(array_filter(
            $this->finder->find($owner),
            static fn (DeadlineBlockage $b): bool => $b->legalCase->getId() === $case->getId(),
        ));
    }

    private function createUser(string $suffix): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($this->testPrefix . '-' . $suffix . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'test'));
        $user->setIsVerified(true);
        $this->em->persist($user);

        return $user;
    }

    private function createCase(CaseStatus $status, ?User $owner = null): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($owner ?? $this->user);
        $case->setStatus($status);
        $this->em->persist($case);

        return $case;
    }

    /** Writes the history entry that proves the case entered IN_ANULARE at some point. */
    private function recordAnnulmentPassage(LegalCase $case): void
    {
        $entry = new CaseStatusHistory();
        $entry->setLegalCase($case);
        $entry->setOldStatus(CaseStatus::ORDONANTA_EMISA->value);
        $entry->setNewStatus(CaseStatus::IN_ANULARE->value);
        $this->em->persist($entry);
    }

    /** Attaches a document of the given type; nothing is written to disk, the row is what the queries read. */
    private function createDocument(LegalCase $case, DocumentType $type): void
    {
        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType($type);
        $document->setOriginalFilename($type->value . '.pdf');
        $document->setStoredFilename($this->testPrefix . '/' . uniqid() . '.pdf');
        $document->setFileSize(1024);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($case->getUser());
        $this->em->persist($document);
    }

    private function createDeadline(LegalCase $case, DeadlineType $type): void
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType($type);
        $deadline->setDeadlineDate(new \DateTimeImmutable('+7 days'));
        $deadline->setPriority($type->defaultPriority());
        $deadline->setCompleted(false);
        $this->em->persist($deadline);
    }
}
