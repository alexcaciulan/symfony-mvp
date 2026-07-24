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

    public function testCreatePaymentNoticeDeadlineUses15DaysProrogated(): void
    {
        // Luni 2 februarie 2026 + 15 zile = marți 17 februarie 2026 (zi lucrătoare, fără prorogare)
        $base = new \DateTimeImmutable('2026-02-02');

        $deadline = $this->service->createPaymentNoticeDeadline($this->case, $base);

        $this->assertSame('2026-02-17', $deadline->getDeadlineDate()->format('Y-m-d'));
        $this->assertSame(DeadlineType::RASPUNS_SOMATIE, $deadline->getType());
    }

    public function testRecalculatePaymentNoticeDeadlineUpdatesExistingAndClearsDisclaimer(): void
    {
        // Estimated deadline first (with a disclaimer, as the workflow subscriber sets it).
        $estimated = $this->service->createPaymentNoticeDeadline($this->case, new \DateTimeImmutable('2026-02-02'));
        $estimated->setDescription('Termen estimativ. ...');
        $this->em->flush();

        $real = new \DateTimeImmutable('2026-02-10'); // actual receipt date
        $recomputed = $this->service->recalculatePaymentNoticeDeadline($this->case, $real);

        // Same deadline row, moved to real date + 15 days, disclaimer cleared.
        $this->assertSame($estimated->getId(), $recomputed->getId());
        // 2026-02-10 (Tue) + 15 days = 2026-02-25 (Wed, working day → no prorogation)
        $this->assertSame('2026-02-25', $recomputed->getDeadlineDate()->format('Y-m-d'));
        $this->assertNull($recomputed->getDescription());
    }

    public function testRecalculatePaymentNoticeDeadlineCreatesWhenMissing(): void
    {
        $recomputed = $this->service->recalculatePaymentNoticeDeadline($this->case, new \DateTimeImmutable('2026-02-02'));

        $this->assertSame(DeadlineType::RASPUNS_SOMATIE, $recomputed->getType());
        $this->assertSame('2026-02-17', $recomputed->getDeadlineDate()->format('Y-m-d'));
    }

    public function testIsPaymentTermExpiredFalseWhenNoCommunicationDate(): void
    {
        // No communication date → term cannot be proven expired.
        self::assertFalse($this->service->isPaymentTermExpired($this->case, new \DateTimeImmutable('2030-01-01')));
    }

    public function testIsPaymentTermExpiredFalseBeforeTermEnd(): void
    {
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-02-02')); // Mon, term end 2026-02-17
        $this->em->flush();

        self::assertFalse($this->service->isPaymentTermExpired($this->case, new \DateTimeImmutable('2026-02-10')));
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
        // CPC art. 706: 3 years from the date the order became final, no prorogation.
        $deadline = $this->service->createExecutionPrescriptionDeadline($this->case, new \DateTimeImmutable('2026-09-11'));

        $this->assertSame(DeadlineType::PRESCRIPTIE_EXECUTARE, $deadline->getType());
        $this->assertSame('2029-09-11', $deadline->getDeadlineDate()->format('Y-m-d'));
    }

    public function testCreatePaymentNoticeDeadlineProrogatesWhenLandsOnHoliday(): void
    {
        // 16 dec 2025 (marți) + 15 zile = 31 dec 2025 (miercuri, zi lucrătoare normală)
        // Folosim un caz unde +15 cade pe sărbătoare: 10 dec 2025 (miercuri) + 15 = 25 dec (joi Crăciun).
        // 25 dec joi (Crăciun) + 26 vineri (Crăciun) + 27 sâmbătă + 28 duminică → luni 29 dec.
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
        // Luni 2 februarie 2026 + 10 zile = joi 12 februarie 2026 (zi lucrătoare)
        $communication = new \DateTimeImmutable('2026-02-02');

        $deadline = $this->service->createAppealDeadline($this->case, $communication);

        $this->assertSame('2026-02-12', $deadline->getDeadlineDate()->format('Y-m-d'));
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
        self::assertSame(['2027-03-15', '2027-06-20', '2027-09-10'], $dates);
        self::assertSame(DeadlinePriority::CRITICAL, $created[0]->getPriority());

        // Idempotent per due date: a second call adds nothing.
        self::assertSame([], $this->service->createPrescriptionDeadlines($case));
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
        $base = new \DateTimeImmutable('2025-12-10'); // +15 zile aterizează pe Crăciun → prorogat
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
        $this->assertSame('2025-12-25', $payload['rawDeadline'] ?? null);
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
