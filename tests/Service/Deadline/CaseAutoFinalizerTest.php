<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Event\MissingCommunicationDateEvent;
use App\Repository\LegalCaseRepository;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Deadline\CaseAutoFinalizer;
use App\Service\Deadline\WorkingDayResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Auto-finalization to DEFINITIVA with CPC art. 181 prorogation + buffer.
 */
class CaseAutoFinalizerTest extends KernelTestCase
{
    private const BUFFER_DAYS = 1;

    private EntityManagerInterface $em;
    private WorkingDayResolver $workingDayResolver;
    private User $user;
    private string $testPrefix;

    /** @var MissingCommunicationDateEvent[] */
    private array $missingEvents = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->workingDayResolver = static::getContainer()->get(WorkingDayResolver::class);
        $this->testPrefix = 'auto-final-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    private function finalizer(): CaseAutoFinalizer
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            if ($event instanceof MissingCommunicationDateEvent) {
                $this->missingEvents[] = $event;
            }

            return $event;
        });

        return new CaseAutoFinalizer(
            static::getContainer()->get(LegalCaseRepository::class),
            $this->workingDayResolver,
            static::getContainer()->get(CaseWorkflowService::class),
            static::getContainer()->get(AuditLogService::class),
            $dispatcher,
            $this->em,
            self::BUFFER_DAYS,
        );
    }

    private function createCase(CaseStatus $status, ?string $communicationDate): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus($status);
        $case->setCourtCaseNumber('800/211/2026');
        if ($communicationDate !== null) {
            $case->setRulingCommunicationDate(new \DateTimeImmutable($communicationDate));
        }
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    /** Finalization threshold (prorogated deadline + buffer) for a communication date. */
    private function threshold(string $communicationDate): \DateTimeImmutable
    {
        $deadline = $this->workingDayResolver->nextWorkingDay(
            (new \DateTimeImmutable($communicationDate))->modify('+10 days'),
        );

        return $deadline->modify('+' . self::BUFFER_DAYS . ' days');
    }

    public function testAutoMarkFinalSkipsWhenCommunicationDateMissing(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA, null);

        $report = $this->finalizer()->process(new \DateTimeImmutable('2027-01-01'));

        $this->assertSame(CaseStatus::ORDONANTA_EMISA, $case->getStatus());
        $this->assertGreaterThanOrEqual(1, $report->missingCommunicationDate);
        $this->assertContains(
            $case->getId(),
            array_map(static fn (MissingCommunicationDateEvent $e): ?int => $e->case->getId(), $this->missingEvents),
        );
    }

    public function testAutoMarkFinalRespectsWeekendProrogation(): void
    {
        // 2026-06-04 (Thu) + 10 = 2026-06-14 (Sun) -> prorogated to Mon 06-15.
        $communicationDate = '2026-06-04';
        $raw = (new \DateTimeImmutable($communicationDate))->modify('+10 days');
        $this->assertFalse($this->workingDayResolver->isWorkingDay($raw), 'raw +10 should fall on a weekend');
        $deadline = $this->workingDayResolver->nextWorkingDay($raw);
        $this->assertNotEquals($raw->format('Y-m-d'), $deadline->format('Y-m-d'), 'prorogation should shift the date');

        $threshold = $this->threshold($communicationDate);
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA, $communicationDate);

        // Cron exactly on the prorogated deadline (before the buffer) -> SKIP.
        $this->finalizer()->process($threshold->modify('-1 day'));
        $this->assertSame(CaseStatus::ORDONANTA_EMISA, $case->getStatus());

        // Cron on the threshold (deadline + buffer) -> FINALIZE.
        $this->finalizer()->process($threshold);
        $this->assertSame(CaseStatus::DEFINITIVA, $case->getStatus());
    }

    public function testAutoMarkFinalRespectsHolidayProrogation(): void
    {
        // 2026-12-15 (Tue) + 10 = 2026-12-25 (Christmas) -> prorogated past the holidays.
        $communicationDate = '2026-12-15';
        $raw = (new \DateTimeImmutable($communicationDate))->modify('+10 days');
        $this->assertTrue($this->workingDayResolver->isHoliday($raw), '2026-12-25 should be a holiday');

        $threshold = $this->threshold($communicationDate);
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA, $communicationDate);

        $this->finalizer()->process($threshold->modify('-1 day'));
        $this->assertSame(CaseStatus::ORDONANTA_EMISA, $case->getStatus());

        $this->finalizer()->process($threshold);
        $this->assertSame(CaseStatus::DEFINITIVA, $case->getStatus());
    }

    public function testAutoMarkFinalSkipsIfAppealAlreadyFiled(): void
    {
        // Case already challenged (IN_ANULARE) is no longer in the ORDONANTA_EMISA set.
        $case = $this->createCase(CaseStatus::IN_ANULARE, '2026-06-04');

        $this->finalizer()->process(new \DateTimeImmutable('2027-01-01'));

        $this->assertSame(CaseStatus::IN_ANULARE, $case->getStatus());
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE al FROM audit_log al WHERE al.entity_id IN (SELECT lc.id FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?)",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        parent::tearDown();
    }
}
