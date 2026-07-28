<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use App\Service\Deadline\DeadlineAgendaFilter;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The agenda queries under a triage selection. Filtering happens in SQL, so what is
 * pinned here is that each pill lists exactly what its counter counts, that several
 * pills are a union rather than an intersection, and that passing no filter leaves
 * every query byte for byte the one the unfiltered page and the counters rely on.
 */
final class LegalDeadlineFilteredAgendaTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LegalDeadlineRepository $repo;
    private User $user;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(LegalDeadlineRepository::class);
        $this->testPrefix = 'deadline-filter-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $ids = [$this->user->getId()];
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

    /**
     * The arrears pill lists what the arrears counter counts, including the exclusion
     * of the debtor's own expired term (CPC art. 1015-1016): a pill that listed
     * something other than its own number would make the bar unreadable.
     */
    public function testTheArrearsPillListsExactlyWhatTheArrearsCounterCounts(): void
    {
        $case = $this->createCase();
        $overdue = $this->createDeadline($case, '-3 days', DeadlineType::TIMBRARE);
        $this->createDeadline($case, 'today', DeadlineType::TIMBRARE);
        $this->createDeadline($case, '+5 days', DeadlineType::CERERE_IN_ANULARE);
        $this->createDeadline($case, '-2 days', DeadlineType::RASPUNS_SOMATIE);
        $this->em->flush();

        $filter = DeadlineAgendaFilter::fromValues('overdue', false);

        self::assertSame([$overdue->getId()], $this->idsOf($this->repo->findOverdueForUser($this->user, $filter)));
        self::assertSame([], $this->repo->findExpiredDebtorTermsForUser($this->user, $filter));
        self::assertSame([], $this->agenda($filter), 'Nothing dated from today on is an arrear.');
        self::assertSame(1, $this->repo->countAgendaBuckets($this->user)['overdue']);
    }

    public function testTheTodayPillKeepsOnlyTheCurrentDay(): void
    {
        $case = $this->createCase();
        $this->createDeadline($case, '-3 days', DeadlineType::TIMBRARE);
        $today = $this->createDeadline($case, 'today', DeadlineType::JUDECATA);
        $this->createDeadline($case, '+1 day', DeadlineType::JUDECATA);
        $this->em->flush();

        $filter = DeadlineAgendaFilter::fromValues('today', false);

        self::assertSame([$today->getId()], $this->idsOf($this->agenda($filter)));
        self::assertSame([], $this->repo->findOverdueForUser($this->user, $filter));
    }

    /**
     * The fatal pill is bound to the legal mapping, not to a second list of types:
     * a hearing inside the window is not fatal, a stamping term is.
     */
    public function testTheFatalPillKeepsOnlyTheIrreversibleTypesInsideTheWindow(): void
    {
        $case = $this->createCase();
        $stampDuty = $this->createDeadline($case, '+4 days', DeadlineType::TIMBRARE);
        $this->createDeadline($case, '+4 days', DeadlineType::JUDECATA);
        $this->createDeadline($case, '+200 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $filter = DeadlineAgendaFilter::fromValues('fatal30', false);

        self::assertSame([$stampDuty->getId()], $this->idsOf($this->agenda($filter)));
        self::assertSame(
            [],
            $this->repo->findLongHorizonPrescriptionsForUser($this->user, 5, $filter),
            'The rail sits beyond the window, so no triage pill can select it.',
        );
    }

    /** Two pills are a union: the point of the bar is to widen the triage, not narrow it. */
    public function testTwoPillsListTheUnionOfWhatEachOfThemCounts(): void
    {
        $case = $this->createCase();
        $overdue = $this->createDeadline($case, '-3 days', DeadlineType::TIMBRARE);
        $today = $this->createDeadline($case, 'today', DeadlineType::JUDECATA);
        $this->createDeadline($case, '+6 days', DeadlineType::JUDECATA);
        $this->em->flush();

        $filter = DeadlineAgendaFilter::fromValues('overdue,today', false);

        self::assertSame([$overdue->getId()], $this->idsOf($this->repo->findOverdueForUser($this->user, $filter)));
        self::assertSame([$today->getId()], $this->idsOf($this->agenda($filter)));
    }

    /**
     * The blockage pill selects cases whose deadline does not exist yet, so on its
     * own it must leave the agenda empty rather than fall through to every row.
     */
    public function testTheBlockagePillAloneLeavesNoRowInTheAgenda(): void
    {
        $case = $this->createCase();
        $this->createDeadline($case, '-3 days', DeadlineType::TIMBRARE);
        $this->createDeadline($case, 'today', DeadlineType::JUDECATA);
        $this->em->flush();

        $filter = DeadlineAgendaFilter::fromValues('blocked', false);

        self::assertSame([], $this->agenda($filter));
        self::assertSame([], $this->repo->findOverdueForUser($this->user, $filter));
        self::assertSame([], $this->repo->findExpiredDebtorTermsForUser($this->user, $filter));
    }

    /**
     * Closed terms are out of the agenda by definition and come back only when asked
     * for. The counters do not follow: they measure what is still open.
     */
    public function testClosedTermsReturnOnlyWhenAskedForAndNeverEnterTheCounters(): void
    {
        $case = $this->createCase();
        $open = $this->createDeadline($case, '-3 days', DeadlineType::TIMBRARE);
        $closed = $this->createDeadline($case, '-4 days', DeadlineType::TIMBRARE, completed: true);
        $this->em->flush();

        self::assertSame([$open->getId()], $this->idsOf($this->repo->findOverdueForUser($this->user)));

        $withCompleted = DeadlineAgendaFilter::fromValues('', true);
        self::assertSame(
            [$closed->getId(), $open->getId()],
            $this->idsOf($this->repo->findOverdueForUser($this->user, $withCompleted)),
        );
        self::assertSame(1, $this->repo->countAgendaBuckets($this->user)['overdue']);
    }

    /** Showing closed terms and pressing a pill compose instead of cancelling out. */
    public function testShowingClosedTermsComposesWithAPill(): void
    {
        $case = $this->createCase();
        $closedToday = $this->createDeadline($case, 'today', DeadlineType::JUDECATA, completed: true);
        $this->createDeadline($case, '+3 days', DeadlineType::JUDECATA, completed: true);
        $this->em->flush();

        self::assertSame(
            [$closedToday->getId()],
            $this->idsOf($this->agenda(DeadlineAgendaFilter::fromValues('today', true))),
        );
    }

    /** @return LegalDeadline[] */
    private function agenda(?DeadlineAgendaFilter $filter = null): array
    {
        return $this->repo->findAgendaForUser(
            $this->user,
            new \DateTimeImmutable('today'),
            new \DateTimeImmutable('today +30 days'),
            $filter,
        );
    }

    /** @param LegalDeadline[] $deadlines */
    private function idsOf(array $deadlines): array
    {
        return array_map(static fn (LegalDeadline $d): int => $d->getId(), $deadlines);
    }

    private function createCase(CaseStatus $status = CaseStatus::AMIABIL): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus($status);
        $this->em->persist($case);

        return $case;
    }

    private function createDeadline(
        LegalCase $case,
        string $date,
        DeadlineType $type,
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
}
