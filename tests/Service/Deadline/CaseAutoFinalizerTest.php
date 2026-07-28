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
use App\Service\Deadline\DeadlineService;
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
    // Isolated from config: the test supplies its own buffer to the constructor so
    // it stays self-contained across config changes (production value is 5).
    private const BUFFER_DAYS = 1;

    private EntityManagerInterface $em;
    private WorkingDayResolver $workingDayResolver;
    private DeadlineService $deadlineService;
    private User $user;
    private string $testPrefix;

    /** @var MissingCommunicationDateEvent[] */
    private array $missingEvents = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->workingDayResolver = static::getContainer()->get(WorkingDayResolver::class);
        // Test-only public alias declared in config/packages/test/services.yaml.
        $this->deadlineService = static::getContainer()->get('test.public.deadline_service');
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
            $this->deadlineService,
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

    /**
     * Finalization threshold (annulment term maturity + working-day buffer) for a
     * communication date. The maturity comes from the same service the
     * CERERE_IN_ANULARE deadline uses, so the test cannot drift from it: 10 free
     * days = 11 calendar days (CPC art. 181 alin. 1 pct. 2), prorogated per alin. 2.
     */
    private function threshold(string $communicationDate): \DateTimeImmutable
    {
        $deadline = $this->deadlineService->appealTermEnd(new \DateTimeImmutable($communicationDate));

        return $this->workingDayResolver->addWorkingDays($deadline, self::BUFFER_DAYS);
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
        // 2026-06-03 (Wed) + 11 calendar days (10 free days) = 2026-06-14 (Sun)
        // -> prorogated to Mon 2026-06-15.
        $communicationDate = '2026-06-03';
        $raw = (new \DateTimeImmutable($communicationDate))->modify('+11 days');
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
        // 2026-12-14 (Mon) + 11 calendar days (10 free days) = 2026-12-25 (Friday,
        // Christmas) -> prorogated past the holidays and the weekend.
        $communicationDate = '2026-12-14';
        $raw = (new \DateTimeImmutable($communicationDate))->modify('+11 days');
        $this->assertTrue($this->workingDayResolver->isHoliday($raw), '2026-12-25 should be a holiday');

        $threshold = $this->threshold($communicationDate);
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA, $communicationDate);

        $this->finalizer()->process($threshold->modify('-1 day'));
        $this->assertSame(CaseStatus::ORDONANTA_EMISA, $case->getStatus());

        $this->finalizer()->process($threshold);
        $this->assertSame(CaseStatus::DEFINITIVA, $case->getStatus());
    }

    /**
     * The debtor may file the annulment request throughout the maturity day of the
     * term (CPC art. 182 alin. 1), so a cron running that very day must leave the case
     * alone: closing it would be declaring final an order still open to challenge.
     *
     * The maturity date is asserted literally, unlike in the tests above where it is
     * derived from the service: Tuesday 9 June 2026 plus the 10 legal days alone would
     * be Friday 19 June, a working day, so only counting the free day of CPC art. 181
     * alin. 1 pct. 2 carries it to Saturday 20 June and from there to Monday 22 June.
     */
    public function testAutoMarkFinalDoesNotFinalizeOnTheMaturityDayItself(): void
    {
        $communicationDate = '2026-06-09';
        $maturity = $this->deadlineService->appealTermEnd(new \DateTimeImmutable($communicationDate));
        $this->assertSame('2026-06-22', $maturity->format('Y-m-d'));

        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA, $communicationDate);

        $this->finalizer()->process($maturity);
        $this->assertSame(CaseStatus::ORDONANTA_EMISA, $case->getStatus());

        // Past the buffer the same case does finalize, so the assertion above is a
        // boundary and not a case that never closes.
        $this->finalizer()->process($this->threshold($communicationDate));
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
        // The entered.DEFINITIVA listener (R1) creates a PRESCRIPTIE_EXECUTARE
        // deadline when the auto-finalizer marks a case final, so deadlines must
        // be removed before the parent legal_case rows.
        $conn->executeStatement(
            "DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
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
