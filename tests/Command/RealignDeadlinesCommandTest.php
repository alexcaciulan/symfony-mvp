<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AuditLog;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The command only touches a deadline whose stored date is PROVABLY what the previous
 * counting rules produced from an anchor still on the case. Everything here is about
 * that proof: what qualifies, what does not, and that a second run is a no-op.
 */
final class RealignDeadlinesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->testPrefix = 'realign-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    /**
     * The annulment term under the previous rule ran for 10 calendar days instead of
     * 11: 1 September 2026 plus 10 is Friday 11 September. Under the current rule it
     * matures on Saturday 12 and is prorogated to Monday 14 (CPC art. 181 alin. 1 pct.
     * 2 and alin. 2), which is where the row has to land.
     */
    public function testATermProducedByThePreviousRuleIsMovedOntoTheCurrentOne(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $deadline = $this->createDeadline($case, DeadlineType::CERERE_IN_ANULARE, '2026-09-11');
        $this->em->flush();

        $output = $this->execute([]);

        $this->em->clear();
        $stored = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertSame('2026-09-14', $stored->getDeadlineDate()->format('Y-m-d'));
        self::assertStringContainsString('Moved: 1', $output);
    }

    /** A date a human moved is not reproducible from the anchor, so it is reported and kept. */
    public function testADateNobodyCanAccountForIsLeftUntouched(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $deadline = $this->createDeadline($case, DeadlineType::CERERE_IN_ANULARE, '2026-09-30');
        $this->em->flush();

        $output = $this->execute([]);

        $this->em->clear();
        $stored = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertSame('2026-09-30', $stored->getDeadlineDate()->format('Y-m-d'));
        self::assertStringContainsString('not_from_previous_rule', $output);
    }

    /** Without the date the term runs from, nothing can be proven, so nothing is done. */
    public function testATermWhoseAnchorWasRemovedIsReportedRatherThanGuessed(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $deadline = $this->createDeadline($case, DeadlineType::CERERE_IN_ANULARE, '2026-09-11');
        $this->em->flush();

        $output = $this->execute([]);

        $this->em->clear();
        $stored = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertSame('2026-09-11', $stored->getDeadlineDate()->format('Y-m-d'));
        self::assertStringContainsString('anchor_missing', $output);
    }

    /**
     * The summons-answer term cannot exist before the communication date is recorded:
     * the fifteen days run from receipt. Such a row is removed, but only when it
     * reproduces the seeding that used to run from the generation date.
     */
    public function testASummonsTermSeededFromTheGenerationDateIsRemoved(): void
    {
        $case = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeDate(new \DateTime('2026-02-02'));
        // 2026-02-02 + 16 = 2026-02-18, what the current rule produces from generation.
        $deadline = $this->createDeadline($case, DeadlineType::RASPUNS_SOMATIE, '2026-02-18');
        $this->em->flush();
        $deadlineId = $deadline->getId();

        $output = $this->execute([]);

        $this->em->clear();
        self::assertNull($this->em->getRepository(LegalDeadline::class)->find($deadlineId));
        self::assertStringContainsString('Removed: 1', $output);
    }

    /** A summons term on a date nothing explains stays, even without a communication date. */
    public function testASummonsTermOnAHandPickedDateIsKept(): void
    {
        $case = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeDate(new \DateTime('2026-02-02'));
        $deadline = $this->createDeadline($case, DeadlineType::RASPUNS_SOMATIE, '2026-03-09');
        $this->em->flush();
        $deadlineId = $deadline->getId();

        $this->execute([]);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(LegalDeadline::class)->find($deadlineId));
    }

    public function testDryRunWritesNothing(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $deadline = $this->createDeadline($case, DeadlineType::CERERE_IN_ANULARE, '2026-09-11');
        $this->em->flush();

        $output = $this->execute(['--dry-run' => true]);

        $this->em->clear();
        $stored = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertSame('2026-09-11', $stored->getDeadlineDate()->format('Y-m-d'));
        self::assertStringContainsString('Moved: 1', $output, 'The dry run reports the same decision it would apply.');
        self::assertStringContainsString('nothing was written', $output);
    }

    /**
     * Idempotent by construction: once a row carries what the current rule produces, it
     * no longer matches the previous one, which is the only thing the run acts on.
     */
    public function testASecondRunChangesNothing(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $this->createDeadline($case, DeadlineType::CERERE_IN_ANULARE, '2026-09-11');
        $this->em->flush();

        $this->execute([]);
        $second = $this->execute([]);

        self::assertStringContainsString('Moved: 0', $second);
        self::assertStringContainsString('Removed: 0', $second);
    }

    /**
     * A closed term is a record of what happened, not a countdown, and the lawyer
     * closed it against the date it carried. Rewriting that date afterwards would
     * rewrite the history of an act that is already done.
     */
    public function testACompletedTermIsNotRewritten(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $deadline = $this->createDeadline($case, DeadlineType::CERERE_IN_ANULARE, '2026-09-11');
        $deadline->setCompleted(true);
        $this->em->flush();

        $output = $this->execute([]);

        $this->em->clear();
        $stored = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertSame('2026-09-11', $stored->getDeadlineDate()->format('Y-m-d'));
        self::assertStringContainsString('Moved: 0', $output);
    }

    /**
     * The run rewrites dates a lawyer relies on and cannot ask him first, so each
     * change has to be readable afterwards: which term, on which case, from which date
     * to which. The old date is what makes the move reversible by hand.
     */
    public function testEveryMoveIsRecordedInTheAuditTrail(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $deadline = $this->createDeadline($case, DeadlineType::CERERE_IN_ANULARE, '2026-09-11');
        $this->em->flush();
        $deadlineId = $deadline->getId();

        $this->execute([]);

        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityType' => LegalDeadline::class,
            'entityId' => (string) $deadlineId,
            'action' => 'deadline_realigned',
        ]);

        self::assertNotNull($log);
        self::assertSame('2026-09-11', $log->getOldData()['deadlineDate'] ?? null);
        self::assertSame('2026-09-14', $log->getNewData()['deadlineDate'] ?? null);
        self::assertSame(DeadlineType::CERERE_IN_ANULARE->value, $log->getNewData()['type'] ?? null);
        self::assertSame(AuditLogService::CATEGORY_DEADLINE_EDITED, $log->getCategory());
    }

    /**
     * A removal leaves nothing behind to inspect, so the entry carries the date and
     * the type of what was dropped, and the reason it could not be kept.
     */
    public function testARemovalIsRecordedWithTheTermItDropped(): void
    {
        $case = $this->createCase(CaseStatus::SOMATIE_TRIMISA);
        $case->setPaymentNoticeDate(new \DateTime('2026-02-02'));
        $deadline = $this->createDeadline($case, DeadlineType::RASPUNS_SOMATIE, '2026-02-18');
        $this->em->flush();
        $deadlineId = $deadline->getId();

        $this->execute([]);

        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'entityType' => LegalDeadline::class,
            'entityId' => (string) $deadlineId,
            'action' => 'deadline_realignment_removed',
        ]);

        self::assertNotNull($log);
        self::assertSame('2026-02-18', $log->getOldData()['deadlineDate'] ?? null);
        self::assertSame(DeadlineType::RASPUNS_SOMATIE->value, $log->getOldData()['type'] ?? null);
        self::assertSame('summons_communication_date_missing', $log->getNewData()['reason'] ?? null);
    }

    /** A dry run reports what it would do and leaves no trace of having decided it. */
    public function testDryRunWritesNoAuditEntryEither(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $deadline = $this->createDeadline($case, DeadlineType::CERERE_IN_ANULARE, '2026-09-11');
        $this->em->flush();

        $this->execute(['--dry-run' => true]);

        self::assertSame([], $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => LegalDeadline::class,
            'entityId' => (string) $deadline->getId(),
        ]));
    }

    /** Nothing is rewritten on a case nobody acts on any more. */
    public function testTerminalCasesAreOutOfScope(): void
    {
        $case = $this->createCase(CaseStatus::INCHIS_SUCCES);
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $deadline = $this->createDeadline($case, DeadlineType::CERERE_IN_ANULARE, '2026-09-11');
        $this->em->flush();

        $this->execute([]);

        $this->em->clear();
        $stored = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        self::assertSame('2026-09-11', $stored->getDeadlineDate()->format('Y-m-d'));
    }

    /** @param array<string, mixed> $input */
    private function execute(array $input): string
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:realign-deadlines'));
        $tester->execute($input);

        return $tester->getDisplay();
    }

    private function createCase(CaseStatus $status): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus($status);
        $this->em->persist($case);

        return $case;
    }

    private function createDeadline(LegalCase $case, DeadlineType $type, string $date): LegalDeadline
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType($type);
        $deadline->setDeadlineDate(new \DateTimeImmutable($date));
        $deadline->setPriority($type->defaultPriority());
        $this->em->persist($deadline);

        return $deadline;
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        // The command runs headless, so its entries carry no user to join on, and a
        // removed deadline leaves an entry pointing at an id that no longer exists.
        // Both actions are produced by this command alone, which makes the action name
        // the only handle available and a sufficient one.
        $conn->executeStatement('DELETE FROM audit_log WHERE action IN (?, ?)', ['deadline_realigned', 'deadline_realignment_removed']);
        $conn->executeStatement('DELETE ld FROM legal_deadline ld JOIN legal_case lc ON ld.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?', [$this->testPrefix . '%']);
        $conn->executeStatement('DELETE FROM user WHERE email LIKE ?', [$this->testPrefix . '%']);

        parent::tearDown();
    }
}
