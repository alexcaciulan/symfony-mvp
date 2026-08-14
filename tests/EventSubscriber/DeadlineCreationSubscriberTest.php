<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\ClaimItem;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\ClaimItemKind;
use App\Enum\DeadlineType;
use App\EventSubscriber\DeadlineCreationSubscriber;
use App\Service\Case\CaseWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Pas 4.2 — Integration tests for DeadlineCreationSubscriber.
 *
 * Reproduce flow real prin `CaseWorkflowService::apply()` + Doctrine flush —
 * verifică că termenele apar automat în DB după acțiunile avocatului. Nu
 * folosește mock-uri pe subscriber sau pe service (model după
 * CaseWorkflowSubscriberTest).
 */
final class DeadlineCreationSubscriberTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CaseWorkflowService $workflowService;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->workflowService = static::getContainer()->get(CaseWorkflowService::class);

        $this->testPrefix = 'deadline-sub-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Deadline');
        $this->user->setLastName('Subscriber');
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (!isset($this->testPrefix)) {
            parent::tearDown();

            return;
        }

        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        // Audit logs cu user — direct prin user_id. Cele fără user (test fără
        // Security context) sunt restrânse la entity_type LegalCase + entity_id
        // pe dosarele user-ului prin JOIN ca să nu afectăm alte teste.
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement(
            "DELETE al FROM audit_log al WHERE al.user_id IS NULL AND al.entity_type = 'LegalCase' AND CAST(al.entity_id AS UNSIGNED) IN (SELECT id FROM legal_case WHERE user_id = ?)",
            [$userId]
        );
        $conn->executeStatement(
            'DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        $conn->executeStatement(
            'DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id WHERE lc.user_id = ?',
            [$userId]
        );
        $conn->executeStatement('DELETE FROM notification WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM user WHERE id = ?', [$userId]);

        parent::tearDown();
    }

    private function newCase(?\DateTime $dueDate = null): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setAmount('1000.00');
        $case->setCurrency('RON');
        if ($dueDate !== null) {
            $case->setDueDate($dueDate);
        }
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    public function testPostPersistCreatesPrescriptionDeadlineWhenDueDateSet(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE,
        ]);

        self::assertCount(1, $deadlines, 'postPersist trebuie să creeze automat termenul PRESCRIPTIE.');
        // dueDate 2024-03-15 + 3 ani = 2027-03-15 (luni, zi lucrătoare → fără prorogare)
        self::assertSame('2027-03-15', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
    }

    public function testPostPersistSkipsPrescriptionDeadlineWhenDueDateNull(): void
    {
        $case = $this->newCase(null); // fără dueDate

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE,
        ]);

        self::assertCount(0, $deadlines, 'Fără dueDate, PRESCRIPTIE nu trebuie creat (skip + log).');
    }

    private function addClaimItem(
        LegalCase $case,
        string $dueDate,
        ClaimItemKind $kind = ClaimItemKind::INVOICE,
        bool $excluded = false,
    ): ClaimItem {
        $item = new ClaimItem();
        $item->setLegalCase($case);
        $item->setDedupKey('ci-' . uniqid('', true));
        $item->setKind($kind);
        $item->setAmount('500.00');
        $item->setCurrency('RON');
        $item->setAmountRon('500.00');
        $item->setDueDate(new \DateTimeImmutable($dueDate));
        $item->setConfirmedByLawyer(true);
        $item->setExcludedByLawyer($excluded);
        $case->addClaimItem($item);

        return $item;
    }

    public function testPostPersistCreatesOnePrescriptionDeadlinePerDistinctDueDate(): void
    {
        // Three invoices on the same file, each with its own due date: three
        // prescription terms, each at due date + 3 years (NCC art. 2517).
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setAmount('1500.00');
        $case->setCurrency('RON');
        $case->setDueDate(new \DateTime('2024-03-15')); // denormalized earliest
        $this->addClaimItem($case, '2024-03-15');
        $this->addClaimItem($case, '2024-06-20');
        $this->addClaimItem($case, '2024-09-10');
        $this->em->persist($case);
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE,
        ]);

        self::assertCount(3, $deadlines, 'Fiecare scadență distinctă trebuie să genereze un termen PRESCRIPTIE.');

        // Due date + 3 years (NCC art. 2517), prorogated to the first working day
        // (NCC art. 2554). 20 June 2027 falls on a Sunday, and the Monday after it is
        // the second day of Pentecost, so the term lands on Tuesday 22 June: the
        // prorogation walks the whole holiday chain, it does not just skip the weekend.
        $dates = array_map(static fn (LegalDeadline $d): string => $d->getDeadlineDate()->format('Y-m-d'), $deadlines);
        sort($dates);
        self::assertSame(['2027-03-15', '2027-06-22', '2027-09-10'], $dates);

        // The covered due date is surfaced in the description so the cards do not
        // read as duplicates in the Tab Termene.
        foreach ($deadlines as $deadline) {
            self::assertNotNull($deadline->getDescription());
            self::assertStringContainsString('2517', (string) $deadline->getDescription());
        }
    }

    public function testPostPersistDedupesPrescriptionDeadlinesForSameDueDate(): void
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setAmount('1000.00');
        $case->setCurrency('RON');
        $case->setDueDate(new \DateTime('2024-03-15'));
        $this->addClaimItem($case, '2024-03-15');
        $this->addClaimItem($case, '2024-03-15'); // same due date, second invoice
        $this->em->persist($case);
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE,
        ]);

        self::assertCount(1, $deadlines, 'Scadențe identice nu trebuie să dubleze termenul de prescripție.');
        self::assertSame('2027-03-15', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
    }

    public function testPostPersistSkipsExcludedAndCreditNotePositionsForPrescription(): void
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setAmount('500.00');
        $case->setCurrency('RON');
        $case->setDueDate(new \DateTime('2024-03-15'));
        $this->addClaimItem($case, '2024-03-15'); // counts
        $this->addClaimItem($case, '2024-06-20', ClaimItemKind::INVOICE, excluded: true); // excluded
        $this->addClaimItem($case, '2024-09-10', ClaimItemKind::CREDIT_NOTE); // credit note
        $this->em->persist($case);
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE,
        ]);

        $dates = array_map(static fn (LegalDeadline $d): string => $d->getDeadlineDate()->format('Y-m-d'), $deadlines);
        self::assertSame(['2027-03-15'], $dates, 'Pozițiile excluse și notele de credit nu generează termen de prescripție.');
    }

    /**
     * The fifteen days of CPC art. 1015 para. 1 run from the debtor RECEIVING the
     * summons. At SOMATIE_TRIMISA only the generation date is known, which is a
     * different date with no legal meaning, so no term is created: the case is carried
     * by the blockage zone until the real date is recorded, and the term is born then.
     */
    public function testSomatieTrimisaCreatesNoPaymentNoticeDeadline(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2026-02-02')); // Monday
        $this->em->flush();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::RASPUNS_SOMATIE,
        ]);

        self::assertCount(0, $deadlines, 'No RASPUNS_SOMATIE term may exist before the communication date is known.');
    }

    /**
     * The other half of the same rule: recording the real communication date is what
     * creates the term, counted from that date and not from the generation one.
     */
    public function testRecordingTheCommunicationDateCreatesThePaymentNoticeDeadline(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2026-02-02'));
        $this->em->flush();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->em->flush();

        $case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-02-02')); // Monday
        $this->em->flush();
        $this->deadlineService()->recalculatePaymentNoticeDeadline($case, new \DateTimeImmutable('2026-02-02'));

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::RASPUNS_SOMATIE,
        ]);

        self::assertCount(1, $deadlines);
        // 15 free days (CPC art. 1015 alin. 1 + art. 181 alin. 1 pct. 2) = 16 calendar
        // days: 2026-02-02 Monday + 16 = 2026-02-18 Wednesday, a working day.
        self::assertSame('2026-02-18', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
    }

    public function testOrdonantaEmisaSkipsWhenRulingCommunicationDateNull(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        // Forțăm status la TERMEN_FIXAT prin apply pe lanțul de tranziții
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $this->em->flush();
        $this->workflowService->apply($case, 'trimite_somatie');
        $this->workflowService->apply($case, 'depune_cerere');
        $this->workflowService->apply($case, 'inregistreaza_dosar');
        $this->workflowService->apply($case, 'fixeaza_termen');
        $this->em->flush();

        // Acum putem aplica emite_ordonanta — fără rulingCommunicationDate
        $this->workflowService->apply($case, 'emite_ordonanta');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::CERERE_IN_ANULARE,
        ]);

        self::assertCount(0, $deadlines, 'Fără rulingCommunicationDate, CERERE_IN_ANULARE nu trebuie creat — UI viitoare va popula câmpul.');
    }

    public function testOrdonantaEmisaCreatesAppealDeadlineWhenRulingCommunicationDateSet(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-02-02'));
        $this->em->flush();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->workflowService->apply($case, 'depune_cerere');
        $this->workflowService->apply($case, 'inregistreaza_dosar');
        $this->workflowService->apply($case, 'fixeaza_termen');
        $this->workflowService->apply($case, 'emite_ordonanta');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::CERERE_IN_ANULARE,
        ]);

        self::assertCount(1, $deadlines);
        // 10 zile libere (CPC art. 1024 alin. 1 + art. 181 alin. 1 pct. 2) = 11 zile
        // calendaristice: 2026-02-02 luni + 11 = 2026-02-13 vineri (zi lucrătoare).
        self::assertSame('2026-02-13', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
    }

    public function testIdempotencyOnDuplicateWorkflowFire(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $this->em->flush();

        $this->advanceToOrdonantaEmisa($case);
        $this->workflowService->apply($case, 'marcheaza_definitiva');
        $this->em->flush();

        // Symfony Workflow does not allow re-applying the same transition, so the
        // listener is fired a second time by hand. `null` for the transition is
        // accepted by EnteredEvent in Symfony 7+.
        /** @var DeadlineCreationSubscriber $subscriber */
        $subscriber = static::getContainer()->get(DeadlineCreationSubscriber::class);
        /** @var WorkflowInterface $workflow */
        $workflow = static::getContainer()->get('state_machine.legal_case');
        $marking = $workflow->getMarking($case);

        $subscriber->onDefinitiva(new EnteredEvent($case, $marking, null, $workflow));
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE_EXECUTARE,
        ]);
        self::assertCount(1, $deadlines, 'A second firing of the listener must not duplicate the term.');
    }

    public function testFullCascadeCreatesTwoDeadlines(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2026-02-02'));
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $this->em->flush();

        // Parcurgere completă a fluxului pas cu pas
        $this->workflowService->apply($case, 'trimite_somatie');
        $this->workflowService->apply($case, 'depune_cerere');
        $this->workflowService->apply($case, 'inregistreaza_dosar');
        $this->workflowService->apply($case, 'fixeaza_termen');
        $this->workflowService->apply($case, 'emite_ordonanta');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
        ]);

        // Two, not three: the summons-answer term is not seeded at SOMATIE_TRIMISA any
        // more, because the date it runs from is the debtor's receipt and that is not
        // recorded on this case.
        $types = array_map(static fn (LegalDeadline $d): DeadlineType => $d->getType(), $deadlines);
        self::assertCount(2, $deadlines);
        self::assertContains(DeadlineType::PRESCRIPTIE, $types);
        self::assertContains(DeadlineType::CERERE_IN_ANULARE, $types);
        self::assertNotContains(DeadlineType::RASPUNS_SOMATIE, $types);
    }

    public function testDefinitivaCreatesExecutionPrescriptionDeadline(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $this->em->flush();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->workflowService->apply($case, 'depune_cerere');
        $this->workflowService->apply($case, 'inregistreaza_dosar');
        $this->workflowService->apply($case, 'fixeaza_termen');
        $this->workflowService->apply($case, 'emite_ordonanta');
        $this->workflowService->apply($case, 'marcheaza_definitiva');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE_EXECUTARE,
        ]);

        self::assertCount(1, $deadlines, 'marcheaza_definitiva must create a PRESCRIPTIE_EXECUTARE deadline (CPC art. 705).');
        // Final the day after the annulment term lapses, + 3 years. The term itself
        // is 10 free days (2026-09-01 + 11 = Saturday 2026-09-12), prorogated to
        // Monday 2026-09-14 (CPC art. 181 alin. 2), so the order is final on
        // 2026-09-15 and the three years run out on Saturday 2029-09-15, prorogated to
        // Monday 2029-09-17 (NCC art. 2554).
        self::assertSame('2029-09-17', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
    }

    /**
     * Enforcement started directly from ORDONANTA_EMISA (CPC art. 1021), bypassing
     * DEFINITIVA. No enforcement-limitation term is created: that term measures the
     * window in which the right to ask for enforcement is still alive, and asking for
     * it is exactly what just happened.
     */
    public function testExecutareCreatesNoExecutionPrescriptionDeadlineOnDirectPath(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $this->em->flush();

        $this->advanceToOrdonantaEmisa($case);
        $this->workflowService->apply($case, 'trece_la_executare');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE_EXECUTARE,
        ]);

        self::assertCount(0, $deadlines, 'Entering enforcement must not create the term it makes pointless.');
    }

    /**
     * The term created on DEFINITIVA is CLOSED when the bailiff registration number of
     * the enforcement request is recorded: that filing interrupts the limitation of the
     * right to enforce (CPC art. 708 para. 1 pt. 2) and the number is what confirms it
     * happened, so from then on nothing is left to count down to.
     */
    public function testExecutareClosesTheExecutionPrescriptionDeadline(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $this->em->flush();

        $this->advanceToOrdonantaEmisa($case);
        $this->workflowService->apply($case, 'marcheaza_definitiva');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE_EXECUTARE,
        ]);
        self::assertCount(1, $deadlines);
        self::assertFalse($deadlines[0]->isCompleted());

        $case->setEnforcementRequestDate(new \DateTimeImmutable('2026-10-05'));
        $case->setEnforcementRegistrationNumber('412/2026');
        $this->workflowService->apply($case, 'trece_la_executare');
        $this->em->flush();
        $this->em->refresh($deadlines[0]);

        self::assertTrue($deadlines[0]->isCompleted(), 'The registered filing with the bailiff closes the term.');
        self::assertNull($deadlines[0]->getCompletedBy(), 'Closed by the platform, not by a lawyer.');
    }

    /**
     * The declared filing date on its own does NOT close the term. The interruption of
     * CPC art. 708 para. 1 pt. 2 attaches to a filing, and until the bailiff sends back
     * the number under which he registered it, the only evidence is the lawyer's own
     * statement. On a term whose expiry extinguishes the right to enforce, that is the
     * wrong side to err on, so the term stays open while its alerts go silent.
     */
    public function testExecutareWithoutTheRegistrationNumberKeepsTheTermOpen(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $this->em->flush();

        $this->advanceToOrdonantaEmisa($case);
        $this->workflowService->apply($case, 'marcheaza_definitiva');
        $this->em->flush();

        $case->setEnforcementRequestDate(new \DateTimeImmutable('2026-10-05'));
        $this->workflowService->apply($case, 'trece_la_executare');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE_EXECUTARE,
        ]);
        self::assertCount(1, $deadlines);
        $this->em->refresh($deadlines[0]);

        self::assertFalse($deadlines[0]->isCompleted(), 'A declared date is not a confirmed filing.');
    }

    /**
     * Entering enforcement without the date of the request filed with the bailiff must
     * NOT close the term. The transition is a status the lawyer declares, while the
     * interruption of CPC art. 708 para. 1 pt. 2 attaches to a dated filing, and CPC
     * art. 708 para. 3 undoes even that interruption when the enforcement is dismissed,
     * annulled, perimed or abandoned. On a term whose expiry extinguishes the right to
     * enforce, staying open costs an alert; closing on a declaration costs the title.
     */
    public function testExecutareKeepsTheExecutionPrescriptionDeadlineOpenWithoutTheRequestDate(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $this->em->flush();

        $this->advanceToOrdonantaEmisa($case);
        $this->workflowService->apply($case, 'marcheaza_definitiva');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE_EXECUTARE,
        ]);
        self::assertCount(1, $deadlines);

        $this->workflowService->apply($case, 'trece_la_executare');
        $this->em->flush();
        $this->em->refresh($deadlines[0]);

        self::assertFalse($deadlines[0]->isCompleted(), 'A declared status must not close the enforcement limitation.');
    }

    /**
     * The date the order becomes final is the day AFTER the annulment term lapses, and
     * that term is read back from `DeadlineService::appealTermEnd()` rather than
     * recomputed here, so the enforcement prescription can never drift from the
     * CERERE_IN_ANULARE deadline the lawyer is shown. Both readings are asserted: the
     * literal dates pin the calculation, the derived one pins the shared source.
     */
    public function testExecutionPrescriptionRunsFromTheDayAfterTheAnnulmentTermMatures(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $case->setRulingCommunicationDate(new \DateTimeImmutable('2026-09-01'));
        $this->em->flush();

        $this->advanceToOrdonantaEmisa($case);
        $this->workflowService->apply($case, 'marcheaza_definitiva');
        $this->em->flush();

        // 10 free days from 1 September 2026 mature on Saturday 12 September and are
        // prorogated to Monday 14 September (CPC art. 181 alin. 1 pct. 2 and alin. 2).
        $maturity = $this->deadlineService()->appealTermEnd(new \DateTimeImmutable('2026-09-01'));
        self::assertSame('2026-09-14', $maturity->format('Y-m-d'));

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE_EXECUTARE,
        ]);

        self::assertCount(1, $deadlines);
        // The raw three years land on Saturday 2029-09-15 and are prorogated to Monday
        // 2029-09-17 (NCC art. 2554); the derivation from the annulment term is what is
        // being pinned here, so the prorogation is applied to the expectation too.
        self::assertSame(
            $this->workingDayResolver()->nextWorkingDay($maturity->modify('+1 day')->modify('+3 years'))->format('Y-m-d'),
            $deadlines[0]->getDeadlineDate()->format('Y-m-d'),
            'The enforcement prescription must be derived from the annulment term, not recomputed beside it.',
        );
        self::assertSame('2029-09-17', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
    }

    /**
     * Second anchor of the chain. Pronouncement always precedes service of the order,
     * so a term counted from it expires before the real one and warns early, which is
     * the safe direction on a term that cannot be reopened.
     */
    public function testExecutionPrescriptionFallsBackToTheRulingDateWhenTheCommunicationDateIsMissing(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $case->setFinalRulingDate(new \DateTime('2026-09-01'));
        $this->em->flush();

        $this->advanceToOrdonantaEmisa($case);
        $this->workflowService->apply($case, 'marcheaza_definitiva');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE_EXECUTARE,
        ]);

        self::assertCount(1, $deadlines);
        // 2029-09-01 is a Saturday, prorogated to Monday 2029-09-03 (NCC art. 2554).
        self::assertSame('2029-09-03', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
    }

    /**
     * Third branch: no anchor at all means no deadline. The current day must never be
     * used, because it is later than the real anchor and would show a term longer than
     * the one that actually runs. The case surfaces in the blockage zone instead.
     */
    public function testExecutionPrescriptionIsNotCreatedWithoutAnyAnchor(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $this->em->flush();

        $this->advanceToOrdonantaEmisa($case);
        $case->setFinalRulingDate(null);
        $this->em->flush();

        $this->workflowService->apply($case, 'trece_la_executare');
        $this->em->flush();

        self::assertSame([], $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE_EXECUTARE,
        ]));
    }

    /**
     * Filing the request is the condition NCC art. 2540 sets for the interruption
     * produced by the summons to hold, so reaching CERERE_DEPUSA settles the six-month
     * term and it closes on its own.
     */
    public function testCerereDepusaClosesTheFilingDeadline(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2024-04-01'));
        $this->em->flush();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->em->flush();

        $filing = $this->deadlineService()->createFilingDeadline($case, new \DateTimeImmutable('2026-02-20'));
        self::assertNotNull($filing);
        self::assertFalse($filing->isCompleted());

        $this->workflowService->apply($case, 'depune_cerere');
        $this->em->flush();

        self::assertTrue($filing->isCompleted());
    }

    private function deadlineService(): \App\Service\Deadline\DeadlineService
    {
        // Test-only public alias declared in config/packages/test/services.yaml.
        return static::getContainer()->get('test.public.deadline_service');
    }

    private function workingDayResolver(): \App\Service\Deadline\WorkingDayResolver
    {
        return static::getContainer()->get(\App\Service\Deadline\WorkingDayResolver::class);
    }

    private function advanceToOrdonantaEmisa(LegalCase $case): void
    {
        foreach (['trimite_somatie', 'depune_cerere', 'inregistreaza_dosar', 'fixeaza_termen', 'emite_ordonanta'] as $transition) {
            $this->workflowService->apply($case, $transition);
        }
        $this->em->flush();
    }

    public function testPrescriptionDeadlineUsesCaseStatusAmiabilAsDefault(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));

        // PRESCRIPTIE se creează la postPersist indiferent de status
        self::assertSame(CaseStatus::AMIABIL, $case->getStatus());

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::PRESCRIPTIE,
        ]);
        self::assertCount(1, $deadlines, 'PRESCRIPTIE se creează imediat la persistarea dosarului.');
    }
}
