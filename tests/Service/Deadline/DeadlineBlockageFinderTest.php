<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineBlockageReason;
use App\Enum\DeadlineType;
use App\Enum\StampDutyStatus;
use App\Service\Deadline\DeadlineBlockage;
use App\Service\Deadline\DeadlineBlockageFinder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The blocked cases behind the blockage zone: the fatal terms that are missing from
 * the agenda because the fact they run from carries no date. Each of the three
 * reasons is pinned together with the state that must NOT raise it, since a false
 * blockage sends the lawyer to record a date that changes nothing.
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
        $conn->executeStatement(
            'DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id IN (?))',
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

    public function testSummonsWithTheCommunicationDateIsNotBlocked(): void
    {
        $case = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeDate(new \DateTime('-5 days'));
        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('-3 days'));
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
     * The risk bar counts CASES here, not deadlines, which only holds because the
     * three reasons apply to disjoint sets of statuses: one case, at most one row.
     * The order is the one the zone renders in, worst gap first.
     */
    public function testEachCaseRaisesAtMostOneBlockageAndTheListIsOrderedBySeverity(): void
    {
        $summons = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $summons->setPaymentNoticeDate(new \DateTime('-5 days'));

        $ruling = $this->createCase(CaseStatus::ORDONANTA_EMISA);

        $stamping = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $stamping->setStampDutyStatus(StampDutyStatus::AMANATA_REGULARIZARE);
        $this->em->flush();

        $blockages = $this->finder->find($this->user);

        self::assertSame(
            [
                DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING,
                DeadlineBlockageReason::RULING_COMMUNICATION_MISSING,
                DeadlineBlockageReason::STAMP_DUTY_NOTICE_MISSING,
            ],
            array_map(static fn (DeadlineBlockage $b): DeadlineBlockageReason => $b->reason, $blockages),
        );

        $caseIds = array_map(static fn (DeadlineBlockage $b): ?int => $b->legalCase->getId(), $blockages);
        self::assertSame([$summons->getId(), $ruling->getId(), $stamping->getId()], $caseIds);
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

    public function testBlockageCarriesTheRouteThatRecordsTheMissingDate(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $this->em->flush();

        $blockage = $this->blockagesFor($case)[0];

        self::assertSame('case_deadline_ruling_date', $blockage->actionRoute());
        self::assertSame(['caseId' => $case->getId()], $blockage->actionRouteParameters());
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
