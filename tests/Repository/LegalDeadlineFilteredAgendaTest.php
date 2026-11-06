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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The agenda queries behind the tabs. Everything happens in SQL, so what is pinned here
 * is that the fatal selection follows the legal mapping in every bucket, that the limitation
 * terms of a case collapse on one row in every list and every number, and what the two
 * lists outside the time buckets contain.
 */
final class LegalDeadlineFilteredAgendaTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LegalDeadlineRepository $repo;
    private User $user;
    private ?User $foreignUser = null;
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
        $ids = array_values(array_filter([$this->user->getId(), $this->foreignUser?->getId()]));
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
     * The fatal selection is bound to the legal mapping, not to a second list of types: a
     * hearing is not fatal, a stamping term is, and an overdue one stays in the selection.
     * The former pill stopped at today, which hid the terms a lawyer most needs to see.
     */
    public function testTheFatalSelectionKeepsIrreversibleRowsInEveryBucket(): void
    {
        $overdueCase = $this->createCase();
        $overdueStamp = $this->createDeadline($overdueCase, '-5 days', DeadlineType::TIMBRARE);
        $consumed = $this->createDeadline($overdueCase, '-2 days', DeadlineType::PRESCRIPTIE);
        $this->createDeadline($overdueCase, '-5 days', DeadlineType::OTHER);

        $futureCase = $this->createCase();
        $annulment = $this->createDeadline($futureCase, 'today', DeadlineType::CERERE_IN_ANULARE);
        $filing = $this->createDeadline($futureCase, '+4 days', DeadlineType::DEPUNERE_CERERE);
        $this->createDeadline($futureCase, '+4 days', DeadlineType::JUDECATA);
        $this->createDeadline($futureCase, '+4 days', DeadlineType::OTHER);
        $this->em->flush();

        $filter = DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_TODO, true);

        self::assertSame(
            [$overdueStamp->getId(), $consumed->getId()],
            $this->idsOf($this->repo->findOverdueForUser($this->user, $filter)),
        );
        self::assertSame(
            [$annulment->getId(), $filing->getId()],
            $this->idsOf($this->agenda($filter)),
        );
    }

    public function testTheFatalSelectionLeavesOutTheExpiredTermsOfTheDebtor(): void
    {
        $case = $this->createCase();
        $this->createDeadline($case, '-2 days', DeadlineType::RASPUNS_SOMATIE);
        $this->em->flush();

        $filter = DeadlineAgendaFilter::of(DeadlineAgendaFilter::TAB_TODO, true);

        self::assertSame([], $this->repo->findExpiredDebtorTermsForUser($this->user, $filter));
        self::assertCount(1, $this->repo->findExpiredDebtorTermsForUser($this->user));
    }

    public function testTheCountersIgnoreTheFatalSelection(): void
    {
        $case = $this->createCase();
        $this->createDeadline($case, '-3 days', DeadlineType::OTHER);
        $this->createDeadline($case, 'today', DeadlineType::JUDECATA);
        $this->em->flush();

        self::assertSame(1, $this->repo->countAgendaBuckets($this->user)['overdue']);
        self::assertSame(2, $this->repo->countAgendaBuckets($this->user)['todo']);
    }

    /**
     * A case has one limitation term per due date, and the page gives them one row, the
     * nearest open one. The other rows of the same selection stay what they are: the
     * single-row rule is a property of the limitation term, not of the case.
     */
    public function testOpenLimitationTermsOfOneCaseCollapseOnTheNearestOne(): void
    {
        $case = $this->createCase();
        $nearest = $this->createDeadline($case, '+5 days', DeadlineType::PRESCRIPTIE);
        $this->createDeadline($case, '+12 days', DeadlineType::PRESCRIPTIE);
        $this->createDeadline($case, '+200 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        self::assertSame([$nearest->getId()], $this->idsOf($this->agenda()));
        self::assertSame([], $this->repo->findLongHorizonPrescriptionsForUser($this->user));
    }

    public function testTwoTermsOnTheSameDayCollapseOnTheLowestIdentifier(): void
    {
        $case = $this->createCase();
        $first = $this->createDeadline($case, '+5 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();
        $this->createDeadline($case, '+5 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        self::assertSame([$first->getId()], $this->idsOf($this->agenda()));
    }

    /** The row is the nearest OPEN term: closing it hands the row to the next one. */
    public function testAClosedLimitationTermNoLongerHoldsTheRow(): void
    {
        $case = $this->createCase();
        $this->createDeadline($case, '+5 days', DeadlineType::PRESCRIPTIE, completed: true);
        $next = $this->createDeadline($case, '+12 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        self::assertSame([$next->getId()], $this->idsOf($this->agenda()));
    }

    public function testLimitationTermsOfDifferentCasesStaySeparate(): void
    {
        $one = $this->createDeadline($this->createCase(), '+5 days', DeadlineType::PRESCRIPTIE);
        $other = $this->createDeadline($this->createCase(), '+5 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        self::assertSame([$one->getId(), $other->getId()], $this->idsOf($this->agenda()));
    }

    /**
     * Only the limitation term merges. Merging a type that can be closed from the row
     * would leave a button acting on one hidden id, and two hearings are two hearings.
     *
     * @return iterable<string, array{DeadlineType}>
     */
    public static function neverMergedTypeProvider(): iterable
    {
        yield 'hearings' => [DeadlineType::JUDECATA];
        yield 'reminders' => [DeadlineType::OTHER];
        yield 'enforcement limitation' => [DeadlineType::PRESCRIPTIE_EXECUTARE];
    }

    #[DataProvider('neverMergedTypeProvider')]
    public function testOtherTypesOfOneCaseNeverCollapse(DeadlineType $type): void
    {
        $case = $this->createCase();
        $this->createDeadline($case, '+5 days', $type);
        $this->createDeadline($case, '+12 days', $type);
        $this->em->flush();

        self::assertCount(2, $this->agenda());
    }

    /**
     * The terms that ran out are one overdue row, and the counter must say one, not one per
     * due date: the badge and the dashboard read the same number. The term still running
     * stays apart.
     */
    public function testAMergedOverdueRowCountsOnceInEveryNumber(): void
    {
        $case = $this->createCase();
        $row = $this->createDeadline($case, '-20 days', DeadlineType::PRESCRIPTIE);
        $this->createDeadline($case, '-10 days', DeadlineType::PRESCRIPTIE);
        $this->createDeadline($case, '+40 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $buckets = $this->repo->countAgendaBuckets($this->user);

        self::assertSame([$row->getId()], $this->idsOf($this->repo->findOverdueForUser($this->user)));
        self::assertSame(1, $buckets['overdue']);
        self::assertSame(1, $buckets['todo']);
        self::assertSame(1, $this->repo->countOverdueByUser($this->user));
        self::assertSame(1, $this->repo->summarizeLongHorizonPrescriptions($this->user)['count']);
    }

    /** The term that stops the merge does not leak from a foreign or closed set. */
    public function testASummaryCountsOnlyTheOpenLimitationTermsOfTheAskedCases(): void
    {
        $case = $this->createCase();
        $this->createDeadline($case, '+5 days', DeadlineType::PRESCRIPTIE);
        $last = '+300 days';
        $this->createDeadline($case, $last, DeadlineType::PRESCRIPTIE);
        $this->createDeadline($case, '+900 days', DeadlineType::PRESCRIPTIE, completed: true);
        $this->createDeadline($case, '+900 days', DeadlineType::JUDECATA);

        $unrelated = $this->createCase();
        $this->createDeadline($unrelated, '+5 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        $summary = $this->repo->summarizeOpenPrescriptionsByCase([$case->getId()]);

        self::assertSame([$case->getId()], array_keys($summary));
        self::assertSame(2, $summary[$case->getId()]['running']['count']);
        self::assertArrayNotHasKey('passed', $summary[$case->getId()]);
        self::assertSame((new \DateTimeImmutable($last))->format('Y-m-d'), $summary[$case->getId()]['running']['last']->format('Y-m-d'));
        self::assertSame([], $this->repo->summarizeOpenPrescriptionsByCase([]));
    }

    public function testTheOpenTermsOfACaseComeBackNearestFirst(): void
    {
        $case = $this->createCase();
        $far = $this->createDeadline($case, '+300 days', DeadlineType::PRESCRIPTIE);
        $near = $this->createDeadline($case, '+5 days', DeadlineType::PRESCRIPTIE);
        $this->createDeadline($case, '+2 days', DeadlineType::PRESCRIPTIE, completed: true);
        $this->createDeadline($case, '+1 day', DeadlineType::JUDECATA);
        $passed = $this->createDeadline($case, '-5 days', DeadlineType::PRESCRIPTIE);
        $this->em->flush();

        self::assertSame([$near->getId(), $far->getId()], $this->idsOf($this->repo->findPrescriptionsMergedWith($near)));
        self::assertSame([$passed->getId()], $this->idsOf($this->repo->findPrescriptionsMergedWith($passed)));
    }

    /** No row limit any more: the tab states every limitation term beyond the window. */
    public function testTheLongHorizonListHasNoLimitAndMatchesItsSummary(): void
    {
        $rows = [];
        for ($i = 0; $i < 7; ++$i) {
            $rows[] = $this->createDeadline($this->createCase(), '+' . (40 + $i) . ' days', DeadlineType::PRESCRIPTIE);
        }
        $this->em->flush();

        $summary = $this->repo->summarizeLongHorizonPrescriptions($this->user);

        self::assertSame($this->idsOf($rows), $this->idsOf($this->repo->findLongHorizonPrescriptionsForUser($this->user)));
        self::assertSame(7, $summary['count']);
        self::assertSame($rows[0]->getDeadlineDate()->format('Y-m-d'), $summary['next']?->format('Y-m-d'));
    }

    public function testAnEmptyLongHorizonSummaryHasNoNextDate(): void
    {
        self::assertSame(['count' => 0, 'next' => null], $this->repo->summarizeLongHorizonPrescriptions($this->user));
    }

    public function testClosedTermsComeBackMostRecentFirstWithUndatedOnesLast(): void
    {
        $case = $this->createCase();
        $older = $this->createDeadline($case, '-9 days', DeadlineType::OTHER);
        $newer = $this->createDeadline($case, '-3 days', DeadlineType::OTHER);
        $undated = $this->createDeadline($case, '-1 day', DeadlineType::OTHER, completed: true);
        $older->markCompleted($this->user);
        $newer->markCompleted($this->user);
        $this->em->flush();

        $conn = $this->em->getConnection();
        $conn->executeStatement('UPDATE legal_deadline SET completed_at = ? WHERE id = ?', ['2026-01-01 10:00:00', $older->getId()]);
        $conn->executeStatement('UPDATE legal_deadline SET completed_at = ? WHERE id = ?', ['2026-02-01 10:00:00', $newer->getId()]);

        self::assertSame(
            [$newer->getId(), $older->getId(), $undated->getId()],
            $this->idsOf($this->repo->findCompletedForUser($this->user, 10)),
        );
        self::assertSame(
            [$newer->getId(), $older->getId()],
            $this->idsOf($this->repo->findCompletedForUser($this->user, 2)),
        );

        $summary = $this->repo->summarizeCompleted($this->user);
        self::assertSame(3, $summary['count']);
        self::assertSame('2026-02-01', $summary['last']?->format('Y-m-d'));
    }

    public function testClosedTermsLeaveOutDeletedTerminalAndForeignCases(): void
    {
        $this->createDeadline($this->createCase(), '-3 days', DeadlineType::OTHER);
        $this->em->flush();

        $deleted = $this->createCase();
        $deleted->setDeletedAt(new \DateTimeImmutable());
        $this->createDeadline($deleted, '-3 days', DeadlineType::OTHER, completed: true);
        $this->createDeadline($this->createCase(CaseStatus::RESPINSA), '-3 days', DeadlineType::OTHER, completed: true);

        $foreign = new User();
        $foreign->setEmail($this->testPrefix . '-foreign@test.com');
        $foreign->setPassword('x');
        $foreign->setIsVerified(true);
        $this->em->persist($foreign);
        $this->foreignUser = $foreign;
        $foreignCase = $this->createCase();
        $foreignCase->setUser($foreign);
        $this->createDeadline($foreignCase, '-3 days', DeadlineType::OTHER, completed: true);

        $own = $this->createDeadline($this->createCase(), '-3 days', DeadlineType::OTHER, completed: true);
        $this->em->flush();

        self::assertSame([$own->getId()], $this->idsOf($this->repo->findCompletedForUser($this->user, 10)));
        self::assertSame(1, $this->repo->summarizeCompleted($this->user)['count']);
    }

    public function testNothingClosedHasNoLastDate(): void
    {
        self::assertSame(['count' => 0, 'last' => null], $this->repo->summarizeCompleted($this->user));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function weekEndProvider(): iterable
    {
        yield 'monday ends on sunday' => ['2026-11-02', '2026-11-08'];
        yield 'wednesday ends on sunday' => ['2026-11-04', '2026-11-08'];
        yield 'saturday ends on sunday' => ['2026-11-07', '2026-11-08'];
        yield 'sunday reaches monday' => ['2026-11-08', '2026-11-09'];
    }

    /** On a Sunday the week would end today, and a term due on Monday must not fold away. */
    #[DataProvider('weekEndProvider')]
    public function testTheWeekGroupNeverEndsBeforeTomorrow(string $today, string $end): void
    {
        self::assertSame($end, LegalDeadlineRepository::weekGroupEnd(new \DateTimeImmutable($today))->format('Y-m-d'));
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
