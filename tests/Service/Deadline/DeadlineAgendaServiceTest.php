<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlineCertainty;
use App\Enum\DeadlineConsequence;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use App\Service\Deadline\DeadlineAgendaGroup;
use App\Service\Deadline\DeadlineAgendaItem;
use App\Service\Deadline\DeadlineAgendaService;
use App\Service\Deadline\DeadlineCertaintyResolver;
use App\Service\Deadline\DeadlineConsequenceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Grouping and ordering of the agenda.
 *
 * Most tests drive the service with a clock pinned to a Wednesday far in the future,
 * so the week boundary is deterministic whatever day the suite runs on, and deadlines
 * are placed relative to that same day. The repository is built on the same clock, so
 * the day the SQL filters on and the day the buckets are cut on are one and the same.
 */
class DeadlineAgendaServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private string $testPrefix;

    /** Reference Wednesday, far enough ahead that every relative date stays in the future. */
    private \DateTimeImmutable $wednesday;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->testPrefix = 'deadline-agenda-svc-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setStatus(CaseStatus::TERMEN_FIXAT);
        $this->em->persist($this->case);
        $this->em->flush();

        $this->wednesday = new \DateTimeImmutable('today +200 days');
        $this->wednesday = $this->wednesday->modify('+' . ((10 - (int) $this->wednesday->format('N')) % 7) . ' days');
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM legal_deadline WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    private function service(\DateTimeImmutable $now): DeadlineAgendaService
    {
        $clock = $this->clockAt($now);

        return new DeadlineAgendaService(
            $this->repositoryOn($clock),
            new DeadlineConsequenceResolver(),
            new DeadlineCertaintyResolver(),
            $clock,
        );
    }

    /**
     * The repository under the same clock as the service. Built here rather than taken
     * from the container so "today" means the pinned day on both sides: the overdue
     * query reads it in SQL, the grouping reads it in PHP, and a test that moved only
     * one of them would be proving nothing about the page.
     */
    private function repositoryOn(ClockInterface $clock): LegalDeadlineRepository
    {
        return new LegalDeadlineRepository(
            static::getContainer()->get('doctrine'),
            new DeadlineConsequenceResolver(),
            $clock,
        );
    }

    private function clockAt(\DateTimeImmutable $now): ClockInterface
    {
        return new MockClock($now);
    }

    private function deadline(\DateTimeImmutable|string $date, DeadlineType $type = DeadlineType::JUDECATA): LegalDeadline
    {
        $deadline = new LegalDeadline();
        $deadline->setLegalCase($this->case);
        $deadline->setType($type);
        $deadline->setDeadlineDate($date instanceof \DateTimeImmutable ? $date : new \DateTimeImmutable($date));
        $deadline->setPriority($type->defaultPriority());
        $this->em->persist($deadline);
        $this->em->flush();

        return $deadline;
    }

    /**
     * @param list<DeadlineAgendaGroup> $groups
     *
     * @return array<string, list<int>> deadline ids per group key, render order preserved
     */
    private function idsByGroup(array $groups): array
    {
        $map = [];
        foreach ($groups as $group) {
            $map[$group->key] = array_map(static fn (DeadlineAgendaItem $i): int => $i->deadline->getId(), $group->items);
        }

        return $map;
    }

    public function testReturnsEveryGroupInRenderOrderEvenWhenEmpty(): void
    {
        $groups = $this->service($this->wednesday)->buildAgenda($this->user);

        self::assertSame(
            [
                DeadlineAgendaGroup::KEY_OVERDUE,
                DeadlineAgendaGroup::KEY_UNBLOCKED,
                DeadlineAgendaGroup::KEY_TODAY,
                DeadlineAgendaGroup::KEY_TOMORROW,
                DeadlineAgendaGroup::KEY_REST_OF_WEEK,
                DeadlineAgendaGroup::KEY_NEXT_30,
            ],
            array_map(static fn (DeadlineAgendaGroup $g): string => $g->key, $groups),
        );

        foreach ($groups as $group) {
            self::assertTrue($group->isEmpty());
            self::assertSame(0, $group->count());
        }
    }

    public function testGroupsDeadlinesByCalendarBucket(): void
    {
        // Wednesday reference: tomorrow is Thursday, the rest of the week runs
        // through Sunday, everything later falls into the 30-day remainder.
        $today = $this->deadline($this->wednesday);
        $tomorrow = $this->deadline($this->wednesday->modify('+1 day'));
        $friday = $this->deadline($this->wednesday->modify('+2 days'));
        $sunday = $this->deadline($this->wednesday->modify('+4 days'));
        $monday = $this->deadline($this->wednesday->modify('+5 days'));
        $lastDay = $this->deadline($this->wednesday->modify('+30 days'));
        $this->deadline($this->wednesday->modify('+31 days'));

        $byGroup = $this->idsByGroup($this->service($this->wednesday)->buildAgenda($this->user));

        self::assertSame([], $byGroup[DeadlineAgendaGroup::KEY_OVERDUE]);
        self::assertSame([$today->getId()], $byGroup[DeadlineAgendaGroup::KEY_TODAY]);
        self::assertSame([$tomorrow->getId()], $byGroup[DeadlineAgendaGroup::KEY_TOMORROW]);
        self::assertSame([$friday->getId(), $sunday->getId()], $byGroup[DeadlineAgendaGroup::KEY_REST_OF_WEEK]);
        self::assertSame([$monday->getId(), $lastDay->getId()], $byGroup[DeadlineAgendaGroup::KEY_NEXT_30]);
    }

    public function testOnSundayTheRestOfTheWeekGroupStaysEmpty(): void
    {
        $sunday = $this->wednesday->modify('+4 days');
        $this->deadline($sunday);
        $inTwoDays = $this->deadline($sunday->modify('+2 days'));

        $byGroup = $this->idsByGroup($this->service($sunday)->buildAgenda($this->user));

        self::assertSame([], $byGroup[DeadlineAgendaGroup::KEY_REST_OF_WEEK]);
        self::assertSame([$inTwoDays->getId()], $byGroup[DeadlineAgendaGroup::KEY_NEXT_30]);
    }

    public function testDeadlineDatedTodayIsNotOverdueWhileYesterdayIs(): void
    {
        // Real current date here: the overdue query reads the calendar itself.
        $today = $this->deadline('today');
        $yesterday = $this->deadline('-1 day');

        $byGroup = $this->idsByGroup($this->service(new \DateTimeImmutable('today 23:30'))->buildAgenda($this->user));

        self::assertSame([$yesterday->getId()], $byGroup[DeadlineAgendaGroup::KEY_OVERDUE]);
        self::assertSame([$today->getId()], $byGroup[DeadlineAgendaGroup::KEY_TODAY]);
    }

    /**
     * The debtor's term running out is the event that opens the filing of the request
     * (CPC art. 1015-1016), not a delay of the lawyer, so it leaves the red section
     * for one of its own while every other past-due type stays behind.
     */
    public function testExpiredDebtorTermsLeaveTheOverdueGroupForTheirOwn(): void
    {
        $summonsAnswer = $this->deadline('-6 days', DeadlineType::RASPUNS_SOMATIE);
        $stampDuty = $this->deadline('-2 days', DeadlineType::TIMBRARE);

        $byGroup = $this->idsByGroup($this->service(new \DateTimeImmutable('today 09:00'))->buildAgenda($this->user));

        self::assertSame([$stampDuty->getId()], $byGroup[DeadlineAgendaGroup::KEY_OVERDUE]);
        self::assertSame([$summonsAnswer->getId()], $byGroup[DeadlineAgendaGroup::KEY_UNBLOCKED]);
    }

    /**
     * Only the expiry moves the row. While the debtor's term still runs, the answer
     * may yet come and filing would be premature, so it stays in its calendar bucket.
     */
    public function testDebtorTermStillRunningStaysInItsCalendarBucket(): void
    {
        $running = $this->deadline($this->wednesday, DeadlineType::RASPUNS_SOMATIE);

        $byGroup = $this->idsByGroup($this->service($this->wednesday)->buildAgenda($this->user));

        self::assertSame([], $byGroup[DeadlineAgendaGroup::KEY_UNBLOCKED]);
        self::assertSame([$running->getId()], $byGroup[DeadlineAgendaGroup::KEY_TODAY]);
    }

    /**
     * The marker under an estimated date says why the date is an estimate, and the
     * reason is the opposite one on a limitation period: there the generating fact is
     * confirmed and what is unmodelled is the interruption (NCC art. 2540).
     */
    public function testEstimateReasonFollowsTheTypeOfTheDeadline(): void
    {
        $this->deadline($this->wednesday, DeadlineType::PRESCRIPTIE);
        $this->deadline($this->wednesday, DeadlineType::CERERE_IN_ANULARE);

        $keys = array_map(
            static fn (DeadlineAgendaItem $i): string => $i->estimateReasonKey,
            $this->itemsOf($this->service($this->wednesday)->buildAgenda($this->user)),
        );

        self::assertSame(
            ['deadlines.row.estimate.prescription_interruption', 'deadlines.row.estimate.unconfirmed_fact'],
            $keys,
        );
    }

    public function testSortsBySeverityInsideAGroupThenByDate(): void
    {
        $hearing = $this->deadline($this->wednesday, DeadlineType::JUDECATA);
        $summonsAnswer = $this->deadline($this->wednesday, DeadlineType::RASPUNS_SOMATIE);
        $limitation = $this->deadline($this->wednesday, DeadlineType::PRESCRIPTIE);
        $free = $this->deadline($this->wednesday, DeadlineType::OTHER);
        $annulmentRequest = $this->deadline($this->wednesday, DeadlineType::CERERE_IN_ANULARE);
        $stampDuty = $this->deadline($this->wednesday, DeadlineType::TIMBRARE);

        $byGroup = $this->idsByGroup($this->service($this->wednesday)->buildAgenda($this->user));

        self::assertSame(
            [
                $limitation->getId(),        // RIGHT_EXTINCTION 50
                $annulmentRequest->getId(),  // FORFEITURE 40
                $stampDuty->getId(),         // CASE_ANNULMENT 30
                $hearing->getId(),           // APPEARANCE 20
                $free->getId(),              // RECORD_KEEPING 10
                $summonsAnswer->getId(),     // NO_SANCTION 0
            ],
            $byGroup[DeadlineAgendaGroup::KEY_TODAY],
        );
    }

    public function testEqualSeverityKeepsTheEarlierDateFirst(): void
    {
        $later = $this->deadline($this->wednesday->modify('+20 days'), DeadlineType::JUDECATA);
        $earlier = $this->deadline($this->wednesday->modify('+10 days'), DeadlineType::JUDECATA);
        $graverButLater = $this->deadline($this->wednesday->modify('+25 days'), DeadlineType::TIMBRARE);

        $byGroup = $this->idsByGroup($this->service($this->wednesday)->buildAgenda($this->user));

        self::assertSame(
            [$graverButLater->getId(), $earlier->getId(), $later->getId()],
            $byGroup[DeadlineAgendaGroup::KEY_NEXT_30],
        );
    }

    public function testOverdueGroupIsAlsoOrderedBySeverity(): void
    {
        $oldHearing = $this->deadline('-9 days', DeadlineType::JUDECATA);
        $recentStampDuty = $this->deadline('-2 days', DeadlineType::TIMBRARE);

        $byGroup = $this->idsByGroup($this->service(new \DateTimeImmutable('today 08:00'))->buildAgenda($this->user));

        self::assertSame([$recentStampDuty->getId(), $oldHearing->getId()], $byGroup[DeadlineAgendaGroup::KEY_OVERDUE]);
    }

    public function testItemCarriesConsequenceCertaintyAndCalendarDaysRemaining(): void
    {
        $this->deadline($this->wednesday->modify('+3 days'), DeadlineType::PRESCRIPTIE_EXECUTARE);

        $item = $this->itemsOf($this->service($this->wednesday)->buildAgenda($this->user))[0];

        self::assertSame(DeadlineConsequence::RIGHT_EXTINCTION, $item->consequence);
        self::assertTrue($item->isIrreversible());
        self::assertSame(50, $item->severityRank());
        self::assertSame(DeadlineCertainty::ESTIMAT, $item->certainty, 'No ruling communication date on the case');
        self::assertSame(3, $item->daysRemaining);
    }

    public function testCertaintyFollowsTheCaseFieldTheTermRunsFrom(): void
    {
        $this->deadline($this->wednesday, DeadlineType::CERERE_IN_ANULARE);

        $estimated = $this->itemsOf($this->service($this->wednesday)->buildAgenda($this->user))[0];
        self::assertSame(DeadlineCertainty::ESTIMAT, $estimated->certainty);

        $this->case->setRulingCommunicationDate(new \DateTimeImmutable('-5 days'));
        $this->em->flush();
        $this->em->clear();

        $certain = $this->itemsOf($this->service($this->wednesday)->buildAgenda($this->user))[0];
        self::assertSame(DeadlineCertainty::CERT, $certain->certainty);
    }

    public function testOverdueItemsReportNegativeDaysRemaining(): void
    {
        $this->deadline('-4 days');

        // Late in the day on purpose: the count is on calendar days, not on hours.
        $item = $this->itemsOf($this->service(new \DateTimeImmutable('today 22:45'))->buildAgenda($this->user))[0];

        self::assertSame(-4, $item->daysRemaining);
    }

    public function testCompletedAndClosedCaseDeadlinesNeverReachAnyGroup(): void
    {
        $visible = $this->deadline($this->wednesday);

        $completed = $this->deadline($this->wednesday);
        $completed->setCompleted(true);

        $closedCase = new LegalCase();
        $closedCase->setUser($this->user);
        $closedCase->setStatus(CaseStatus::INCHIS_SUCCES);
        $this->em->persist($closedCase);

        $onClosedCase = new LegalDeadline();
        $onClosedCase->setLegalCase($closedCase);
        $onClosedCase->setType(DeadlineType::PRESCRIPTIE);
        $onClosedCase->setDeadlineDate($this->wednesday);
        $onClosedCase->setPriority(DeadlineType::PRESCRIPTIE->defaultPriority());
        $this->em->persist($onClosedCase);
        $this->em->flush();

        $ids = array_merge(...array_values($this->idsByGroup($this->service($this->wednesday)->buildAgenda($this->user))));

        self::assertSame([$visible->getId()], $ids);
    }

    /**
     * @param list<DeadlineAgendaGroup> $groups
     *
     * @return list<DeadlineAgendaItem> every item, groups flattened in render order
     */
    private function itemsOf(array $groups): array
    {
        $items = [];
        foreach ($groups as $group) {
            foreach ($group->items as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
