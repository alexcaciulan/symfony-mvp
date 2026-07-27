<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Enum\PersonType;
use App\Repository\LegalDeadlineRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Queries behind the agenda page: findAgendaForUser, findOverdueForUser,
 * countAgendaBuckets and findLongHorizonPrescriptionsForUser.
 *
 * The traps pinned here are the ones the deadline analysis called out: a deadline
 * dated today is not overdue (CPC art. 182 para. 1), and closed, soft-deleted,
 * completed or foreign rows must never reach the agenda or its counters.
 */
class LegalDeadlineAgendaTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LegalDeadlineRepository $repo;
    private User $user;
    private User $otherUser;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(LegalDeadlineRepository::class);
        $this->testPrefix = 'deadline-agenda-' . uniqid();

        $this->user = $this->createUser('owner');
        $this->otherUser = $this->createUser('other');
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $ids = [$this->user->getId(), $this->otherUser->getId()];
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id IN (?))',
            [$ids],
            [ArrayParameterType::INTEGER],
        );
        $conn->executeStatement(
            'DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id IN (?))',
            [$ids],
            [ArrayParameterType::INTEGER],
        );
        $conn->executeStatement(
            'DELETE FROM legal_case WHERE user_id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        );
        $conn->executeStatement(
            'DELETE FROM `user` WHERE id IN (?)',
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        parent::tearDown();
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

    private function createCase(?User $owner = null, CaseStatus $status = CaseStatus::AMIABIL): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($owner ?? $this->user);
        $case->setStatus($status);
        $this->em->persist($case);

        return $case;
    }

    private function createDeadline(
        LegalCase $case,
        string $date,
        DeadlineType $type = DeadlineType::JUDECATA,
        bool $completed = false,
    ): LegalDeadline {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($case);
        $deadline->setType($type);
        $deadline->setDeadlineDate(new \DateTimeImmutable($date));
        $deadline->setPriority($type->defaultPriority());
        $deadline->setCompleted($completed);
        $this->em->persist($deadline);

        return $deadline;
    }

    /** @param LegalDeadline[] $deadlines */
    private function idsOf(array $deadlines): array
    {
        return array_map(static fn (LegalDeadline $d): int => $d->getId(), $deadlines);
    }

    private function agenda(): array
    {
        return $this->repo->findAgendaForUser(
            $this->user,
            new \DateTimeImmutable('today'),
            new \DateTimeImmutable('today +30 days'),
        );
    }

    public function testDeadlineDatedTodayCountsAsTodayAndNotAsOverdue(): void
    {
        $case = $this->createCase();
        $today = $this->createDeadline($case, 'today');
        $this->em->flush();

        self::assertContains($today->getId(), $this->idsOf($this->agenda()));
        self::assertNotContains($today->getId(), $this->idsOf($this->repo->findOverdueForUser($this->user)));

        $buckets = $this->repo->countAgendaBuckets($this->user);
        self::assertSame(1, $buckets['today']);
        self::assertSame(0, $buckets['overdue']);
    }

    public function testDeadlineOnTerminalCaseIsNeitherListedNorCounted(): void
    {
        $closed = $this->createCase(status: CaseStatus::INCHIS_SUCCES);
        $this->createDeadline($closed, 'today');
        $this->createDeadline($closed, '-3 days');
        $this->createDeadline($closed, '+10 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        self::assertSame([], $this->agenda());
        self::assertSame([], $this->repo->findOverdueForUser($this->user));
        self::assertSame(
            ['overdue' => 0, 'today' => 0, 'fatal30' => 0],
            $this->repo->countAgendaBuckets($this->user),
        );
    }

    public function testDeadlineOnSoftDeletedCaseIsNeitherListedNorCounted(): void
    {
        $deleted = $this->createCase();
        $deleted->setDeletedAt(new \DateTimeImmutable());
        $this->createDeadline($deleted, 'today');
        $this->createDeadline($deleted, '-2 days');
        $this->em->flush();

        self::assertSame([], $this->agenda());
        self::assertSame([], $this->repo->findOverdueForUser($this->user));
        self::assertSame(
            ['overdue' => 0, 'today' => 0, 'fatal30' => 0],
            $this->repo->countAgendaBuckets($this->user),
        );
    }

    public function testCompletedDeadlineIsNeitherListedNorCounted(): void
    {
        $case = $this->createCase();
        $this->createDeadline($case, 'today', completed: true);
        $this->createDeadline($case, '-4 days', completed: true);
        $this->createDeadline($case, '+5 days', DeadlineType::PRESCRIPTIE, completed: true);
        $this->em->flush();

        self::assertSame([], $this->agenda());
        self::assertSame([], $this->repo->findOverdueForUser($this->user));
        self::assertSame(
            ['overdue' => 0, 'today' => 0, 'fatal30' => 0],
            $this->repo->countAgendaBuckets($this->user),
        );
    }

    public function testDeadlinesOfAnotherUserAreNotVisible(): void
    {
        $foreignCase = $this->createCase($this->otherUser);
        $foreign = $this->createDeadline($foreignCase, 'today');
        $foreignOverdue = $this->createDeadline($foreignCase, '-1 day');
        $foreignPrescription = $this->createDeadline($foreignCase, '+200 days', DeadlineType::PRESCRIPTIE);

        $ownCase = $this->createCase();
        $own = $this->createDeadline($ownCase, 'today');
        $this->em->flush();

        self::assertSame([$own->getId()], $this->idsOf($this->agenda()));
        self::assertSame([], $this->repo->findOverdueForUser($this->user));
        self::assertSame([], $this->repo->findLongHorizonPrescriptionsForUser($this->user));
        self::assertSame(
            ['overdue' => 0, 'today' => 1, 'fatal30' => 0],
            $this->repo->countAgendaBuckets($this->user),
        );

        // The other user still sees their own rows, so the scoping is not a blanket filter.
        self::assertEqualsCanonicalizing(
            [$foreign->getId(), $foreignOverdue->getId()],
            $this->idsOf(array_merge(
                $this->repo->findAgendaForUser($this->otherUser, new \DateTimeImmutable('today'), new \DateTimeImmutable('today +30 days')),
                $this->repo->findOverdueForUser($this->otherUser),
            )),
        );
        self::assertSame([$foreignPrescription->getId()], $this->idsOf($this->repo->findLongHorizonPrescriptionsForUser($this->otherUser)));
    }

    public function testCountAgendaBucketsMatchesManualCounting(): void
    {
        $case = $this->createCase();
        $this->createDeadline($case, '-9 days');
        $this->createDeadline($case, '-1 day', DeadlineType::TIMBRARE);
        $this->createDeadline($case, 'today');
        $this->createDeadline($case, 'today', DeadlineType::PRESCRIPTIE);
        $this->createDeadline($case, '+2 days', DeadlineType::CERERE_IN_ANULARE);
        $this->createDeadline($case, '+29 days', DeadlineType::PRESCRIPTIE_EXECUTARE);
        $this->createDeadline($case, '+31 days', DeadlineType::TIMBRARE);
        $this->createDeadline($case, '+45 days');

        // Rows that must not reach any counter.
        $this->createDeadline($case, 'today', completed: true);
        $closed = $this->createCase(status: CaseStatus::RESPINSA);
        $this->createDeadline($closed, 'today', DeadlineType::PRESCRIPTIE);
        $foreign = $this->createCase($this->otherUser);
        $this->createDeadline($foreign, '-1 day', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $buckets = $this->repo->countAgendaBuckets($this->user);
        $today = new \DateTimeImmutable('today');
        $agenda = $this->agenda();

        $manualToday = \count(array_filter(
            $agenda,
            static fn (LegalDeadline $d): bool => $d->getDeadlineDate()->format('Y-m-d') === $today->format('Y-m-d'),
        ));
        $manualFatal30 = \count(array_filter(
            $agenda,
            static fn (LegalDeadline $d): bool => \in_array($d->getType(), [
                DeadlineType::TIMBRARE,
                DeadlineType::CERERE_IN_ANULARE,
                DeadlineType::PRESCRIPTIE,
                DeadlineType::PRESCRIPTIE_EXECUTARE,
            ], true),
        ));

        self::assertSame(\count($this->repo->findOverdueForUser($this->user)), $buckets['overdue']);
        self::assertSame($manualToday, $buckets['today']);
        self::assertSame($manualFatal30, $buckets['fatal30']);
        self::assertSame(['overdue' => 2, 'today' => 2, 'fatal30' => 3], $buckets);
    }

    public function testFatal30CountsOnlyTheIrreversibleTypesInsideTheHorizon(): void
    {
        $case = $this->createCase();
        // DEPUNERE_CERERE is fatal too: filing past the six months of NCC art. 2540
        // cancels the interruption produced by the summons, so the claim is met with
        // prescription.
        foreach ([DeadlineType::TIMBRARE, DeadlineType::CERERE_IN_ANULARE, DeadlineType::PRESCRIPTIE, DeadlineType::PRESCRIPTIE_EXECUTARE, DeadlineType::DEPUNERE_CERERE] as $fatal) {
            $this->createDeadline($case, '+7 days', $fatal);
        }
        foreach ([DeadlineType::JUDECATA, DeadlineType::RASPUNS_SOMATIE, DeadlineType::OTHER] as $harmless) {
            $this->createDeadline($case, '+7 days', $harmless);
        }
        // Fatal, but outside the horizon on either side.
        $this->createDeadline($case, '-1 day', DeadlineType::TIMBRARE);
        $this->createDeadline($case, '+31 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        self::assertSame(5, $this->repo->countAgendaBuckets($this->user)['fatal30']);
    }

    public function testOverdueListHoldsOnlyPastDatesOrderedAscending(): void
    {
        $case = $this->createCase();
        $oldest = $this->createDeadline($case, '-10 days');
        $newest = $this->createDeadline($case, '-1 day');
        $this->createDeadline($case, 'today');
        $this->createDeadline($case, '+1 day');
        $this->em->flush();

        self::assertSame([$oldest->getId(), $newest->getId()], $this->idsOf($this->repo->findOverdueForUser($this->user)));
    }

    /**
     * The expiry of the debtor's own term (CPC art. 1015 para. 1) is what opens the
     * filing of the request, not a delay of the lawyer, so it must not reach the red
     * section nor the number the sidebar badge and the "Overdue" pill both read.
     */
    public function testExpiredDebtorTermIsNeitherOverdueNorCountedAsSuch(): void
    {
        $case = $this->createCase();
        $summonsAnswer = $this->createDeadline($case, '-6 days', DeadlineType::RASPUNS_SOMATIE);
        $stampDuty = $this->createDeadline($case, '-2 days', DeadlineType::TIMBRARE);
        $this->em->flush();

        self::assertSame([$stampDuty->getId()], $this->idsOf($this->repo->findOverdueForUser($this->user)));
        self::assertSame([$summonsAnswer->getId()], $this->idsOf($this->repo->findExpiredDebtorTermsForUser($this->user)));
        self::assertSame(1, $this->repo->countAgendaBuckets($this->user)['overdue']);
    }

    /**
     * The split is on expiry, not on the type: a debtor term still running is an
     * ordinary future row, and neither past-due query may claim it.
     */
    public function testDebtorTermStillRunningReachesNeitherPastDueQuery(): void
    {
        $case = $this->createCase();
        $running = $this->createDeadline($case, '+4 days', DeadlineType::RASPUNS_SOMATIE);
        $this->em->flush();

        self::assertSame([], $this->repo->findOverdueForUser($this->user));
        self::assertSame([], $this->repo->findExpiredDebtorTermsForUser($this->user));
        self::assertContains($running->getId(), $this->idsOf($this->agenda()));
        self::assertSame(0, $this->repo->countAgendaBuckets($this->user)['overdue']);
    }

    /**
     * The rail names the debtor on every row, and the collection is lazy, so without
     * a deliberate warm-up each row would fire its own SELECT. The row limit must
     * survive it: the debtors cannot be fetch joined into the limited query itself.
     */
    public function testLongHorizonPrescriptionsArriveWithTheirDebtorsLoaded(): void
    {
        $case = $this->createCase();
        $prescription = $this->createDeadline($case, '+400 days', DeadlineType::PRESCRIPTIE);
        foreach (['Debitor Unu SRL', 'Debitor Doi SRL'] as $name) {
            $debtor = new Debtor();
            $debtor->setLegalCase($case);
            $debtor->setPersonType(PersonType::PJ);
            $debtor->setName($name);
            $debtor->setAddress('str. Testului nr. 1');
            $this->em->persist($debtor);
        }
        $this->em->flush();
        $this->em->clear();

        $result = $this->repo->findLongHorizonPrescriptionsForUser(
            $this->em->getRepository(User::class)->find($this->user->getId()),
        );

        self::assertSame([$prescription->getId()], $this->idsOf($result));

        $debtors = $result[0]->getLegalCase()->getDebtors();
        self::assertInstanceOf(PersistentCollection::class, $debtors);
        self::assertTrue($debtors->isInitialized(), 'Reading the debtor on the rail must not trigger a lazy load per row.');
        self::assertCount(2, $debtors);
    }

    public function testAgendaWindowIsInclusiveOnBothBoundsAndOrderedAscending(): void
    {
        $case = $this->createCase();
        $first = $this->createDeadline($case, 'today');
        $last = $this->createDeadline($case, 'today +30 days');
        $this->createDeadline($case, 'today +31 days');
        $this->createDeadline($case, '-1 day');
        $middle = $this->createDeadline($case, 'today +4 days');
        $this->em->flush();

        self::assertSame([$first->getId(), $middle->getId(), $last->getId()], $this->idsOf($this->agenda()));
    }

    public function testAgendaFetchJoinDoesNotDuplicateRowsWithSeveralDebtors(): void
    {
        $case = $this->createCase();
        $deadline = $this->createDeadline($case, 'today');
        foreach (['Debitor Unu SRL', 'Debitor Doi SRL'] as $name) {
            $debtor = new Debtor();
            $debtor->setLegalCase($case);
            $debtor->setPersonType(PersonType::PJ);
            $debtor->setName($name);
            $debtor->setAddress('str. Testului nr. 1');
            $this->em->persist($debtor);
        }
        $this->em->flush();
        $this->em->clear();

        $result = $this->repo->findAgendaForUser(
            $this->em->getRepository(User::class)->find($this->user->getId()),
            new \DateTimeImmutable('today'),
            new \DateTimeImmutable('today +30 days'),
        );

        self::assertSame([$deadline->getId()], $this->idsOf($result));
        self::assertCount(2, $result[0]->getLegalCase()->getDebtors());
    }

    public function testLongHorizonPrescriptionsAreLimitedOrderedAndTypeFiltered(): void
    {
        $case = $this->createCase();
        $near = $this->createDeadline($case, '+20 days', DeadlineType::PRESCRIPTIE);
        $this->createDeadline($case, '+90 days', DeadlineType::TIMBRARE);
        $far1 = $this->createDeadline($case, '+60 days', DeadlineType::PRESCRIPTIE);
        $far2 = $this->createDeadline($case, '+40 days', DeadlineType::PRESCRIPTIE_EXECUTARE);
        $far3 = $this->createDeadline($case, '+400 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $result = $this->repo->findLongHorizonPrescriptionsForUser($this->user);
        self::assertSame([$far2->getId(), $far1->getId(), $far3->getId()], $this->idsOf($result));
        self::assertNotContains($near->getId(), $this->idsOf($result));

        self::assertSame([$far2->getId(), $far1->getId()], $this->idsOf($this->repo->findLongHorizonPrescriptionsForUser($this->user, 2)));
    }

    public function testLongHorizonPrescriptionsIgnoreClosedDeletedAndCompletedRows(): void
    {
        $closed = $this->createCase(status: CaseStatus::INCHIS_FARA_RECUPERARE);
        $this->createDeadline($closed, '+120 days', DeadlineType::PRESCRIPTIE);

        $deleted = $this->createCase();
        $deleted->setDeletedAt(new \DateTimeImmutable());
        $this->createDeadline($deleted, '+120 days', DeadlineType::PRESCRIPTIE);

        $live = $this->createCase();
        $this->createDeadline($live, '+120 days', DeadlineType::PRESCRIPTIE, completed: true);
        $visible = $this->createDeadline($live, '+150 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        self::assertSame([$visible->getId()], $this->idsOf($this->repo->findLongHorizonPrescriptionsForUser($this->user)));
    }
}
