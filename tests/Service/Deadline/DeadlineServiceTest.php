<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\AuditLog;
use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Service\AuditLogService;
use App\Service\Deadline\DeadlineService;
use App\Service\Deadline\WorkingDayResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 4.1 — Tests for DeadlineService.
 *
 * Verifică calculele datelor pe baza temeiurilor legale (CPC art. 1015 alin. 1
 * pentru somație 15 zile, CPC art. 1024 alin. 1 pentru cerere în anulare 10
 * zile, NCC art. 2517 pentru prescripție 3 ani) + prorogare CPC art. 181
 * alin. 2 + audit log + idempotency markCompleted.
 */
final class DeadlineServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DeadlineService $service;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        // Test-only public alias declared in config/packages/test/services.yaml.
        $this->service = $container->get('test.public.deadline_service');

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('deadline-test-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Deadline');
        $this->user->setLastName('Tester');
        $this->em->persist($this->user);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setAmount('1000.00');
        $this->case->setCurrency('RON');
        $this->case->setDueDate(new \DateTime('2024-03-15')); // DATE_MUTABLE legacy
        $this->em->persist($this->case);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id OR user_id IS NULL', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        parent::tearDown();
    }

    public function testCreatePaymentNoticeDeadlineCountsFifteenFreeDays(): void
    {
        // CPC art. 181 alin. 1 pct. 2 (zile libere): nu se socotesc nici ziua de la
        // care curge termenul, nici ziua împlinirii, deci 15 zile acoperă 16 zile
        // calendaristice. Luni 2 februarie 2026 + 16 = miercuri 18 februarie 2026
        // (zi lucrătoare, fără prorogare).
        $base = new \DateTimeImmutable('2026-02-02');

        $deadline = $this->service->createPaymentNoticeDeadline($this->case, $base);

        $this->assertSame('2026-02-18', $deadline->getDeadlineDate()->format('Y-m-d'));
        $this->assertSame(DeadlineType::RASPUNS_SOMATIE, $deadline->getType());
    }

    /**
     * Worked example checked against the free-days rule (CPC art. 181 alin. 1
     * pct. 2) for a summons served on Monday 1 June 2026: the 15-day term matures
     * on Wednesday 17 June 2026 and the 10-day ones on Friday 12 June 2026. All
     * three land on working days, so no prorogation is involved and the dates
     * isolate the N + 1 rule alone.
     */
    public function testFreeDaysWorkedExampleForACommunicationOnFirstOfJune2026(): void
    {
        $communication = new \DateTimeImmutable('2026-06-01');

        $summons = $this->service->createPaymentNoticeDeadline($this->case, $communication);
        $appeal = $this->service->createAppealDeadline($this->case, $communication);
        $stampDuty = $this->service->createStampDutyDeadline($this->case, $communication);

        $this->assertSame('2026-06-17', $summons->getDeadlineDate()->format('Y-m-d'));
        $this->assertSame('2026-06-12', $appeal->getDeadlineDate()->format('Y-m-d'));
        $this->assertSame('2026-06-12', $stampDuty->getDeadlineDate()->format('Y-m-d'));
    }

    /**
     * Order of the two operations, isolated on a case where it is visible.
     *
     * Thursday 4 June 2026 plus the 15 legal days alone lands on Friday 19 June, a
     * working day, so a calculation that stopped at N would never reach the
     * prorogation at all. Counted as free days (CPC art. 181 alin. 1 pct. 2) the term
     * matures on Saturday 20 June, and only then does alin. 2 move it to Monday 22
     * June. Doing it the other way round, prorogating first and adding the free day
     * afterwards, gives the Saturday back.
     *
     * The audit payload is asserted together with the date because it is where the
     * intermediate result is recorded: `rawDeadline` has to be the Saturday.
     */
    public function testTheFreeDayIsCountedBeforeTheProrogationNotAfter(): void
    {
        $resolver = static::getContainer()->get(WorkingDayResolver::class);
        $base = new \DateTimeImmutable('2026-06-04');

        $this->assertTrue(
            $resolver->isWorkingDay($base->modify('+15 days')),
            'Friday 19 June must be a working day, so only the free day can trigger a prorogation here.',
        );
        $this->assertFalse(
            $resolver->isWorkingDay($base->modify('+16 days')),
            'Saturday 20 June is the raw maturity date the prorogation has to act on.',
        );

        $deadline = $this->service->createPaymentNoticeDeadline($this->case, $base);

        $this->assertSame('2026-06-22', $deadline->getDeadlineDate()->format('Y-m-d'));

        $log = $this->em->getRepository(AuditLog::class)->findOneBy(
            ['entityType' => LegalDeadline::class, 'entityId' => (string) $deadline->getId(), 'action' => 'deadline_created'],
        );
        $this->assertNotNull($log);
        $this->assertSame('2026-06-20', $log->getNewData()['rawDeadline'] ?? null);
        $this->assertTrue($log->getNewData()['prorogated'] ?? false);
    }

    /**
     * Same ordering check on the 10-day term: Tuesday 9 June 2026 plus 10 days is
     * Friday 19 June (working day), plus the free day is Saturday 20 June, prorogated
     * to Monday 22 June.
     */
    public function testTheAnnulmentTermIsProrogatedFromItsFreeDaysMaturity(): void
    {
        $deadline = $this->service->createAppealDeadline($this->case, new \DateTimeImmutable('2026-06-09'));

        $this->assertSame('2026-06-22', $deadline->getDeadlineDate()->format('Y-m-d'));
    }

    /**
     * `appealTermEnd()` exists so that the dates derived from the annulment term (the
     * day the order becomes final, the auto-finalization threshold) come from the same
     * calculation the lawyer is shown, instead of each caller repeating it. The two
     * must therefore be the same date, prorogation included: 1 September 2026 plus 11
     * calendar days is Saturday 12 September, prorogated to Monday 14 September.
     */
    public function testAppealTermEndIsTheSameDateTheAnnulmentDeadlineCarries(): void
    {
        $communication = new \DateTimeImmutable('2026-09-01');

        $termEnd = $this->service->appealTermEnd($communication);
        $deadline = $this->service->createAppealDeadline($this->case, $communication);

        $this->assertSame('2026-09-14', $termEnd->format('Y-m-d'));
        $this->assertSame($termEnd->format('Y-m-d'), $deadline->getDeadlineDate()->format('Y-m-d'));
    }

    /**
     * NCC art. 2540, to which CPC art. 1015 alin. 2 refers: the interruption produced
     * by the communicated summons holds only if the claim is filed within six months
     * of that communication. Counted in months (NCC art. 2552 alin. 1), so the term
     * ends on the corresponding day of the sixth month, with no free-days N + 1 and no
     * procedural prorogation.
     */
    public function testFilingDeadlineIsSixMonthsFromTheSummonsCommunicationDate(): void
    {
        $deadline = $this->service->createFilingDeadline($this->case, new \DateTimeImmutable('2026-02-20'));

        $this->assertNotNull($deadline);
        $this->assertSame('2026-08-20', $deadline->getDeadlineDate()->format('Y-m-d'));
        $this->assertSame(DeadlineType::DEPUNERE_CERERE, $deadline->getType());
        $this->assertNotNull($deadline->getDescription());
    }

    /**
     * NCC art. 2552 alin. 2: when the last month has no day corresponding to the one
     * the term started on, it ends on the last day of that month. PHP on its own would
     * overflow 31 August plus six months into 3 March. 28 February 2027 is a Sunday, so
     * the prorogation of NCC art. 2554 then carries it to Monday 1 March: the two rules
     * apply in that order, the month arithmetic first and the working day after it.
     */
    public function testFilingDeadlineStopsAtTheLastDayOfAMonthWithoutACorrespondingDay(): void
    {
        $deadline = $this->service->createFilingDeadline($this->case, new \DateTimeImmutable('2026-08-31'));

        $this->assertNotNull($deadline);
        $this->assertSame('2027-03-01', $deadline->getDeadlineDate()->format('Y-m-d'));
    }

    /**
     * Substantive-law term, prorogated to the first working day under NCC art. 2554,
     * the counterpart of CPC art. 181 alin. 2: 15 August 2026 is both a Saturday and a
     * public holiday, so the term is fulfilled at the close of Monday 17 August, which
     * is the real maturity date and therefore the one shown.
     */
    public function testFilingDeadlineIsProrogatedToTheNextWorkingDay(): void
    {
        $deadline = $this->service->createFilingDeadline($this->case, new \DateTimeImmutable('2026-02-15'));

        $this->assertNotNull($deadline);
        $this->assertSame('2026-08-17', $deadline->getDeadlineDate()->format('Y-m-d'));
    }

    /**
     * The six months run from the communication of the summons, so the date the
     * summons PDF was produced must not reach the calculation even when the case
     * carries it. Generation on 5 January 2026 would have given 5 July 2026; what the
     * term is measured from is the service on 20 February.
     */
    public function testFilingDeadlineIgnoresTheDateTheSummonsWasGenerated(): void
    {
        $this->case->setPaymentNoticeDate(new \DateTime('2026-01-05'));
        $this->em->flush();

        $deadline = $this->service->createFilingDeadline($this->case, new \DateTimeImmutable('2026-02-20'));

        $this->assertNotNull($deadline);
        $this->assertSame('2026-08-20', $deadline->getDeadlineDate()->format('Y-m-d'));
    }

    public function testFilingDeadlineIsRecomputedWhenTheCommunicationDateIsCorrected(): void
    {
        $first = $this->service->createFilingDeadline($this->case, new \DateTimeImmutable('2026-02-20'));
        $corrected = $this->service->createFilingDeadline($this->case, new \DateTimeImmutable('2026-03-02'));

        $this->assertNotNull($first);
        $this->assertNotNull($corrected);
        $this->assertSame($first->getId(), $corrected->getId(), 'A case carries one filing term, not one per correction.');
        $this->assertSame('2026-09-02', $corrected->getDeadlineDate()->format('Y-m-d'));
    }

    /**
     * Past CERERE_DEPUSA the condition of NCC art. 2540 is already met, so there is
     * nothing left to count down to.
     */
    public function testFilingDeadlineIsNotCreatedOnceTheRequestIsFiled(): void
    {
        $case = $this->freshCaseWithoutSubscriberDeadline();
        $case->setStatus(CaseStatus::CERERE_DEPUSA);
        $this->em->flush();

        $this->assertNull($this->service->createFilingDeadline($case, new \DateTimeImmutable('2026-02-20')));
    }

    /** Closed by the platform, not by a lawyer, so no user is recorded on it. */
    public function testCloseFilingDeadlineCompletesItWithoutAUser(): void
    {
        $this->service->createFilingDeadline($this->case, new \DateTimeImmutable('2026-02-20'));

        $closed = $this->service->closeFilingDeadline($this->case);

        $this->assertNotNull($closed);
        $this->assertTrue($closed->isCompleted());
        $this->assertNull($closed->getCompletedBy());
        $this->assertNotNull($closed->getCompletedAt());
    }

    public function testCloseFilingDeadlineIsANoOpWithoutOne(): void
    {
        $this->assertNull($this->service->closeFilingDeadline($this->freshCaseWithoutSubscriberDeadline()));
    }

    public function testRecalculatePaymentNoticeDeadlineUpdatesExistingAndClearsDisclaimer(): void
    {
        // Estimated deadline first (with a disclaimer, as the workflow subscriber sets it).
        $estimated = $this->service->createPaymentNoticeDeadline($this->case, new \DateTimeImmutable('2026-02-02'));
        $estimated->setDescription('Termen estimativ. ...');
        $this->em->flush();

        $real = new \DateTimeImmutable('2026-02-10'); // actual receipt date
        $recomputed = $this->service->recalculatePaymentNoticeDeadline($this->case, $real);

        // Same deadline row, moved to the real receipt date + 15 free days, disclaimer cleared.
        $this->assertSame($estimated->getId(), $recomputed->getId());
        // 2026-02-10 (Tue) + 16 calendar days (15 free days, CPC art. 181 alin. 1
        // pct. 2) = 2026-02-26 (Thu, working day → no prorogation)
        $this->assertSame('2026-02-26', $recomputed->getDeadlineDate()->format('Y-m-d'));
        $this->assertNull($recomputed->getDescription());
    }

    public function testRecalculatePaymentNoticeDeadlineCreatesWhenMissing(): void
    {
        $recomputed = $this->service->recalculatePaymentNoticeDeadline($this->case, new \DateTimeImmutable('2026-02-02'));

        $this->assertSame(DeadlineType::RASPUNS_SOMATIE, $recomputed->getType());
        // 2026-02-02 (Mon) + 16 calendar days (15 free days) = 2026-02-18 (Wed).
        $this->assertSame('2026-02-18', $recomputed->getDeadlineDate()->format('Y-m-d'));
    }

    public function testIsPaymentTermExpiredFalseWhenNoCommunicationDate(): void
    {
        // No communication date → term cannot be proven expired.
        self::assertFalse($this->service->isPaymentTermExpired($this->case, new \DateTimeImmutable('2030-01-01')));
    }

    public function testIsPaymentTermExpiredFalseBeforeTermEnd(): void
    {
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-02-02')); // Mon, term end 2026-02-18
        $this->em->flush();

        self::assertFalse($this->service->isPaymentTermExpired($this->case, new \DateTimeImmutable('2026-02-10')));
    }

    /**
     * The boundary the OP filing gate rests on. With free-days counting (CPC art.
     * 181 alin. 1 pct. 2) a summons served on Monday 2 February 2026 gives the
     * debtor until the end of Wednesday 18 February, so a petition filed that same
     * day is premature and inadmissible (CPC art. 1015-1016). Only from 19
     * February is the term proven expired.
     */
    public function testIsPaymentTermExpiredOnlyFromTheDayAfterTheMaturityDate(): void
    {
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-02-02'));
        $this->em->flush();

        self::assertFalse($this->service->isPaymentTermExpired($this->case, new \DateTimeImmutable('2026-02-17')));
        self::assertFalse($this->service->isPaymentTermExpired($this->case, new \DateTimeImmutable('2026-02-18')));
        self::assertTrue($this->service->isPaymentTermExpired($this->case, new \DateTimeImmutable('2026-02-19')));
    }

    /**
     * The same gate, on a term whose maturity is prorogated. The raw maturity is
     * Saturday 20 June 2026 and CPC art. 181 alin. 2 carries it to Monday 22 June, so
     * the debtor has that whole Monday to pay and the request is admissible only from
     * Tuesday 23 June. A gate reading the raw date instead would have declared the
     * term expired over the weekend and let a premature petition through (CPC art.
     * 1015-1016).
     */
    public function testIsPaymentTermExpiredWaitsForTheProrogatedMaturityDate(): void
    {
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-06-04'));
        $this->em->flush();

        self::assertFalse($this->service->isPaymentTermExpired($this->case, new \DateTimeImmutable('2026-06-21')));
        self::assertFalse($this->service->isPaymentTermExpired($this->case, new \DateTimeImmutable('2026-06-22')));
        self::assertTrue($this->service->isPaymentTermExpired($this->case, new \DateTimeImmutable('2026-06-23')));
    }

    public function testIsPaymentTermExpiredTrueAfterTermEnd(): void
    {
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-02-02'));
        $this->em->flush();

        self::assertTrue($this->service->isPaymentTermExpired($this->case, new \DateTimeImmutable('2026-03-01')));
    }

    public function testRecommendedExecutionDateNullWhenNotDefinitiva(): void
    {
        $this->case->setStatus(CaseStatus::ORDONANTA_EMISA);
        $this->case->setRulingCommunicationDate(new \DateTimeImmutable('2026-02-02'));
        $this->em->flush();

        self::assertNull($this->service->recommendedExecutionDate($this->case));
    }

    public function testRecommendedExecutionDateNullWhenNoCommunicationDate(): void
    {
        $this->case->setStatus(CaseStatus::DEFINITIVA);
        $this->em->flush();

        self::assertNull($this->service->recommendedExecutionDate($this->case));
    }

    public function testRecommendedExecutionDateAdds40WorkingDaysFromCommunication(): void
    {
        $this->case->setStatus(CaseStatus::DEFINITIVA);
        $this->case->setRulingCommunicationDate(new \DateTimeImmutable('2026-02-02'));
        $this->em->flush();

        // 2026-02-02 + 40 days = 2026-03-14 (Saturday) → next working day Mon 2026-03-16.
        $recommended = $this->service->recommendedExecutionDate($this->case);
        self::assertNotNull($recommended);
        self::assertSame('2026-03-16', $recommended->format('Y-m-d'));
    }

    public function testCreateExecutionPrescriptionDeadlineUses3YearsFromDefinitiveDate(): void
    {
        // CPC art. 705: 3 years from the date the order became final, no prorogation.
        $deadline = $this->service->createExecutionPrescriptionDeadline($this->case, new \DateTimeImmutable('2026-09-11'));

        $this->assertSame(DeadlineType::PRESCRIPTIE_EXECUTARE, $deadline->getType());
        $this->assertSame('2029-09-11', $deadline->getDeadlineDate()->format('Y-m-d'));
    }

    public function testCreatePaymentNoticeDeadlineProrogatesWhenLandsOnHoliday(): void
    {
        // 10 dec 2025 (miercuri) + 16 zile calendaristice (15 zile libere) = 26 dec
        // 2025 (vineri, a doua zi de Crăciun). Prorogare CPC art. 181 alin. 2:
        // 27 sâmbătă + 28 duminică → luni 29 decembrie 2025.
        $base = new \DateTimeImmutable('2025-12-10');

        $deadline = $this->service->createPaymentNoticeDeadline($this->case, $base);

        $this->assertSame('2025-12-29', $deadline->getDeadlineDate()->format('Y-m-d'));
    }

    public function testCreatePaymentNoticeDeadlineSetsPriorityHigh(): void
    {
        $deadline = $this->service->createPaymentNoticeDeadline($this->case, new \DateTimeImmutable('2026-02-02'));

        $this->assertSame(DeadlinePriority::HIGH, $deadline->getPriority());
    }

    public function testCreateAppealDeadlineUses10DaysFromCommunicationDate(): void
    {
        // 10 zile libere (CPC art. 1024 alin. 1 + art. 181 alin. 1 pct. 2) = 11 zile
        // calendaristice: luni 2 februarie 2026 + 11 = vineri 13 februarie 2026
        // (zi lucrătoare, fără prorogare).
        $communication = new \DateTimeImmutable('2026-02-02');

        $deadline = $this->service->createAppealDeadline($this->case, $communication);

        $this->assertSame('2026-02-13', $deadline->getDeadlineDate()->format('Y-m-d'));
        $this->assertSame(DeadlineType::CERERE_IN_ANULARE, $deadline->getType());
    }

    public function testCreateAppealDeadlineSetsPriorityCritical(): void
    {
        $deadline = $this->service->createAppealDeadline($this->case, new \DateTimeImmutable('2026-02-02'));

        $this->assertSame(DeadlinePriority::CRITICAL, $deadline->getPriority());
    }

    /**
     * A case persisted with no due date is a no-op for the postPersist subscriber,
     * so the prescription deadlines under test are only the ones this method
     * creates, not one the subscriber added at persist time.
     */
    private function freshCaseWithoutSubscriberDeadline(): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setAmount('1500.00');
        $case->setCurrency('RON');
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    public function testCreatePrescriptionDeadlinesCreatesOnePerDistinctPositionDueDate(): void
    {
        $case = $this->freshCaseWithoutSubscriberDeadline();
        foreach (['2024-03-15', '2024-06-20', '2024-09-10'] as $due) {
            $item = new ClaimItem();
            $item->setLegalCase($case);
            $item->setDedupKey('svc-ci-' . uniqid('', true));
            $item->setAmount('500.00');
            $item->setCurrency('RON');
            $item->setAmountRon('500.00');
            $item->setDueDate(new \DateTimeImmutable($due));
            $item->setConfirmedByLawyer(true);
            $case->addClaimItem($item);
            $this->em->persist($item);
        }
        $this->em->flush();

        $created = $this->service->createPrescriptionDeadlines($case);

        self::assertCount(3, $created);
        $dates = array_map(static fn (LegalDeadline $d): string => $d->getDeadlineDate()->format('Y-m-d'), $created);
        sort($dates);
        // 20 June 2027 is a Sunday followed by the second day of Pentecost, so the
        // prorogation of NCC art. 2554 carries that one to Tuesday 22 June.
        self::assertSame(['2027-03-15', '2027-06-22', '2027-09-10'], $dates);
        self::assertSame(DeadlinePriority::CRITICAL, $created[0]->getPriority());

        // Idempotent per due date: a second call adds nothing.
        self::assertSame([], $this->service->createPrescriptionDeadlines($case));
    }

    /**
     * The prorogation of NCC art. 2554, the substantive-law counterpart of CPC art. 181
     * alin. 2, applied to the limitation period itself: three years from a due date of
     * Tuesday 15 September 2026 run out on Saturday 15 September 2029, and the term is
     * only fulfilled at the close of the first working day after it, Monday 17
     * September. Shown that way because it is the real maturity: an action brought on
     * the Monday is still in time.
     *
     * The raw date stays in the audit payload. It is the only place the untouched
     * arithmetic survives, and without it a shifted date could not be told apart from
     * a wrong one.
     */
    public function testAPrescriptionMaturingOnASaturdayIsProrogatedToTheMonday(): void
    {
        $dueDate = new \DateTimeImmutable('2026-09-15');
        $term = $this->service->limitationTermEnd(DeadlineType::PRESCRIPTIE, $dueDate);

        self::assertNotNull($term);
        self::assertSame('2029-09-15', $term->rawEnd->format('Y-m-d'), 'Three years land on a Saturday.');
        self::assertSame('2029-09-17', $term->end->format('Y-m-d'));

        $case = $this->freshCaseWithoutSubscriberDeadline();
        $case->setDueDate(new \DateTime('2026-09-15'));
        $this->em->flush();

        $created = $this->service->createPrescriptionDeadlines($case);

        self::assertCount(1, $created);
        self::assertSame('2029-09-17', $created[0]->getDeadlineDate()->format('Y-m-d'));

        $log = $this->em->getRepository(AuditLog::class)->findOneBy(
            ['entityType' => LegalDeadline::class, 'entityId' => (string) $created[0]->getId(), 'action' => 'deadline_created'],
        );
        self::assertNotNull($log);
        self::assertSame('2029-09-15', $log->getNewData()['rawDeadline'] ?? null);
        self::assertTrue($log->getNewData()['prorogated'] ?? false);
    }

    /**
     * A term already maturing on a working day is left where it is, so the shift above
     * is a rule and not a blanket offset applied to every limitation period.
     */
    public function testAPrescriptionMaturingOnAWorkingDayIsNotMoved(): void
    {
        $term = $this->service->limitationTermEnd(DeadlineType::PRESCRIPTIE, new \DateTimeImmutable('2026-09-17'));

        self::assertNotNull($term);
        self::assertSame('2029-09-17', $term->rawEnd->format('Y-m-d'));
        self::assertSame($term->rawEnd->format('Y-m-d'), $term->end->format('Y-m-d'));
    }

    public function testCreatePrescriptionDeadlinesFallsBackToCaseDueDateWithoutPositions(): void
    {
        $case = $this->freshCaseWithoutSubscriberDeadline();
        // Setting the due date after persist is a postUpdate, which the subscriber
        // ignores, so the fallback path is what creates the single term here.
        $case->setDueDate(new \DateTime('2024-03-15'));
        $this->em->flush();

        $created = $this->service->createPrescriptionDeadlines($case);

        self::assertCount(1, $created);
        self::assertSame('2027-03-15', $created[0]->getDeadlineDate()->format('Y-m-d'));
    }

    public function testCreatePrescriptionDeadlinesReturnsEmptyWhenNoDueDateAnywhere(): void
    {
        // No positions and no scalar due date: nothing to base a prescription term
        // on, so the method is a graceful no-op rather than throwing.
        $case = $this->freshCaseWithoutSubscriberDeadline();

        self::assertSame([], $this->service->createPrescriptionDeadlines($case));
    }

    public function testCreateHearingDeadlineSetsDateAsIsWhenWorkingDay(): void
    {
        $hearing = new \DateTimeImmutable('2026-09-15'); // marți

        $deadline = $this->service->createHearingDeadline($this->case, $hearing, 'Sala C2');

        $this->assertSame('2026-09-15', $deadline->getDeadlineDate()->format('Y-m-d'));
        $this->assertSame(DeadlineType::JUDECATA, $deadline->getType());
        $this->assertSame(DeadlinePriority::MEDIUM, $deadline->getPriority());
        $this->assertSame('Sala C2', $deadline->getDescription());
    }

    /**
     * The hearing date is not a term that matures, it is the day the court fixed and
     * the summons states, so CPC art. 181 alin. 2 does not apply to it. A date on a
     * non-working day means the portal reading or the entry is wrong, which is an
     * anomaly to report, not to correct: silently moving it would show the lawyer a
     * day other than the one on the summons. Saturday 19 September 2026 is stored as
     * received, and the audit payload marks it.
     */
    public function testCreateHearingDeadlineKeepsANonWorkingDayAsReceivedAndFlagsIt(): void
    {
        $saturday = new \DateTimeImmutable('2026-09-19');

        $deadline = $this->service->createHearingDeadline($this->case, $saturday);

        $this->assertSame('2026-09-19', $deadline->getDeadlineDate()->format('Y-m-d'));

        $log = $this->em->getRepository(AuditLog::class)->findOneBy(
            ['entityType' => LegalDeadline::class, 'entityId' => (string) $deadline->getId(), 'action' => 'deadline_created'],
        );
        $this->assertNotNull($log);
        $this->assertTrue($log->getNewData()['nonWorkingDay'] ?? false);
    }

    /** No flag on the normal case, so the marker stays a signal instead of noise. */
    public function testCreateHearingDeadlineOnAWorkingDayCarriesNoAnomalyFlag(): void
    {
        $deadline = $this->service->createHearingDeadline($this->case, new \DateTimeImmutable('2026-09-15'));

        $log = $this->em->getRepository(AuditLog::class)->findOneBy(
            ['entityType' => LegalDeadline::class, 'entityId' => (string) $deadline->getId(), 'action' => 'deadline_created'],
        );
        $this->assertNotNull($log);
        $this->assertArrayNotHasKey('nonWorkingDay', $log->getNewData());
    }

    public function testCreateHearingDeadlineDescriptionDefaultsToNull(): void
    {
        $deadline = $this->service->createHearingDeadline($this->case, new \DateTimeImmutable('2026-09-15'));

        $this->assertNull($deadline->getDescription());
    }

    public function testMarkCompletedSetsFlagsAndUser(): void
    {
        $deadline = $this->service->createPaymentNoticeDeadline($this->case, new \DateTimeImmutable('2026-02-02'));

        $this->service->markCompleted($deadline, $this->user);

        $this->assertTrue($deadline->isCompleted());
        $this->assertNotNull($deadline->getCompletedAt());
        $this->assertSame($this->user->getId(), $deadline->getCompletedBy()?->getId());
    }

    public function testMarkCompletedIsIdempotent(): void
    {
        $deadline = $this->service->createPaymentNoticeDeadline($this->case, new \DateTimeImmutable('2026-02-02'));

        $this->service->markCompleted($deadline, $this->user);
        $firstCompletedAt = $deadline->getCompletedAt();

        // Apel repetat — nu trebuie să rescrie completedAt
        $this->service->markCompleted($deadline, $this->user);

        $this->assertSame($firstCompletedAt, $deadline->getCompletedAt());
    }

    public function testCreateDeadlinePersistsAuditLogWithCategoryAndPayload(): void
    {
        $base = new \DateTimeImmutable('2025-12-10'); // +16 zile aterizează pe Crăciun → prorogat
        $deadline = $this->service->createPaymentNoticeDeadline($this->case, $base);

        $auditEntries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_DEADLINE_CREATED,
            'entityType' => LegalDeadline::class,
            'entityId' => (string) $deadline->getId(),
        ]);

        $this->assertCount(1, $auditEntries);
        $payload = $auditEntries[0]->getNewData();
        $this->assertSame(DeadlineType::RASPUNS_SOMATIE->value, $payload['type'] ?? null);
        $this->assertSame('2025-12-10', $payload['baseDate'] ?? null);
        // Raw maturity before prorogation: 10 dec + 16 zile calendaristice.
        $this->assertSame('2025-12-26', $payload['rawDeadline'] ?? null);
        $this->assertSame('2025-12-29', $payload['deadlineDate'] ?? null);
        $this->assertTrue($payload['prorogated'] ?? false);
    }

    public function testMarkCompletedPersistsAuditLog(): void
    {
        $deadline = $this->service->createPaymentNoticeDeadline($this->case, new \DateTimeImmutable('2026-02-02'));
        $this->service->markCompleted($deadline, $this->user);

        $auditEntries = $this->em->getRepository(AuditLog::class)->findBy([
            'category' => AuditLogService::CATEGORY_DEADLINE_COMPLETED,
            'entityType' => LegalDeadline::class,
            'entityId' => (string) $deadline->getId(),
        ]);

        $this->assertCount(1, $auditEntries);
    }

    public function testMultipleDeadlinesCoexistOnSameCase(): void
    {
        // PRESCRIPTIE e deja creată automat de DeadlineCreationSubscriber pe
        // postPersist LegalCase (Pas 4.2) — folosim service-ul direct pentru a
        // crea un al doilea tip distinct (RASPUNS_SOMATIE). Verificăm că ambele
        // tipuri coexistă pe același dosar.
        $this->service->createPaymentNoticeDeadline($this->case, new \DateTimeImmutable('2026-02-02'));

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy(['legalCase' => $this->case->getId()]);
        $types = array_map(static fn (LegalDeadline $d): DeadlineType => $d->getType(), $deadlines);

        $this->assertContains(DeadlineType::RASPUNS_SOMATIE, $types);
        $this->assertContains(DeadlineType::PRESCRIPTIE, $types);
        $this->assertCount(2, $deadlines);
    }
}
