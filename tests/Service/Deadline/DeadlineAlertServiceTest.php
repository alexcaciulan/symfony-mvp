<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DeadlinePriority;
use App\Enum\DeadlineType;
use App\Event\DeadlineAlertEvent;
use App\Repository\LegalDeadlineRepository;
use App\Service\Deadline\DeadlineAlertService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DeadlineAlertServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $user;
    private LegalCase $case;
    private string $testPrefix;

    /** @var DeadlineAlertEvent[] */
    private array $dispatched = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->testPrefix = 'deadline-alert-' . uniqid();

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
    }

    private function service(): DeadlineAlertService
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            if ($event instanceof DeadlineAlertEvent) {
                $this->dispatched[] = $event;
            }

            return $event;
        });

        return new DeadlineAlertService(
            static::getContainer()->get(LegalDeadlineRepository::class),
            $dispatcher,
            $this->em,
        );
    }

    private function deadline(string $date, bool $completed = false, ?callable $flags = null, DeadlineType $type = DeadlineType::JUDECATA): LegalDeadline
    {
        $d = new LegalDeadline();
        $d->setLegalCase($this->case);
        $d->setType($type);
        $d->setDeadlineDate(new \DateTimeImmutable($date));
        $d->setPriority(DeadlinePriority::MEDIUM);
        $d->setCompleted($completed);
        if ($flags !== null) {
            $flags($d);
        }
        $this->em->persist($d);
        $this->em->flush();

        return $d;
    }

    /** @return DeadlineAlertEvent[] alerts dispatched for the given deadline */
    private function eventsFor(LegalDeadline $deadline): array
    {
        return array_values(array_filter(
            $this->dispatched,
            static fn (DeadlineAlertEvent $e): bool => $e->deadline->getId() === $deadline->getId(),
        ));
    }

    /**
     * What the case page promises the lawyer about the next reminder has to be the
     * ladder the job actually walks, and that ladder differs by type: a limitation term
     * is warned about a month ahead, a hearing a week ahead. The card used to print
     * 7 / 3 / 1 for everything, which was a promise the job stopped keeping the day the
     * limitation tiers were added.
     */
    public function testNextAlertDaysBeforeFollowsTheLadderOfTheType(): void
    {
        $hearing = $this->deadline('2026-09-01');
        $limitation = $this->deadline('2029-09-01', type: DeadlineType::PRESCRIPTIE);
        $filing = $this->deadline('2027-01-01', type: DeadlineType::DEPUNERE_CERERE);

        $service = $this->service();

        self::assertSame(7, $service->nextAlertDaysBefore($hearing));
        self::assertSame(30, $service->nextAlertDaysBefore($limitation));
        self::assertSame(60, $service->nextAlertDaysBefore($filing));
    }

    /** Past the last tier only the expiry alert is left, and past that one nothing is. */
    public function testNextAlertDaysBeforeFallsToExpiryAndThenToNothing(): void
    {
        $deadline = $this->deadline('2026-09-01', flags: static function (LegalDeadline $d): void {
            $d->setAlertSent7(true)->setAlertSent3(true)->setAlertSent1(true);
        });

        $service = $this->service();
        self::assertSame(0, $service->nextAlertDaysBefore($deadline));

        $deadline->setAlertSentExpired(true);
        self::assertNull($service->nextAlertDaysBefore($deadline));
    }

    public function testFiresThresholdAlertsAndSetsFlags(): void
    {
        $now = new \DateTimeImmutable('2026-06-01 09:00');
        $d7 = $this->deadline('2026-06-08');   // +7
        $d3 = $this->deadline('2026-06-04');   // +3
        $d1 = $this->deadline('2026-06-02');   // +1
        $dExpired = $this->deadline('2026-05-30'); // -2
        $dFar = $this->deadline('2026-07-15');     // +44, no alert

        $this->service()->processAlerts($now);
        $this->em->clear();
        $repo = $this->em->getRepository(LegalDeadline::class);

        $this->assertTrue($repo->find($d7->getId())->isAlertSent7());
        $this->assertCount(1, $this->eventsFor($d7));
        $this->assertSame(7, $this->eventsFor($d7)[0]->daysRemaining);

        // 3-day alert sets 7 + 3 (backfill), exactly one event.
        $r3 = $repo->find($d3->getId());
        $this->assertTrue($r3->isAlertSent7());
        $this->assertTrue($r3->isAlertSent3());
        $this->assertFalse($r3->isAlertSent1());
        $this->assertCount(1, $this->eventsFor($d3));
        $this->assertSame(3, $this->eventsFor($d3)[0]->daysRemaining);

        $r1 = $repo->find($d1->getId());
        $this->assertTrue($r1->isAlertSent1());
        $this->assertCount(1, $this->eventsFor($d1));

        $rExp = $repo->find($dExpired->getId());
        $this->assertTrue($rExp->isAlertSentExpired());
        $this->assertCount(1, $this->eventsFor($dExpired));
        $this->assertSame(-2, $this->eventsFor($dExpired)[0]->daysRemaining);

        $this->assertFalse($repo->find($dFar->getId())->isAlertSent7());
        $this->assertCount(0, $this->eventsFor($dFar));
    }

    public function testDoesNotRefireAlreadySentAlert(): void
    {
        $now = new \DateTimeImmutable('2026-06-01 09:00');
        $d = $this->deadline('2026-06-08', flags: fn (LegalDeadline $x) => $x->setAlertSent7(true)); // +7 already sent

        $this->service()->processAlerts($now);

        $this->assertCount(0, $this->eventsFor($d));
    }

    public function testIgnoresCompletedDeadlines(): void
    {
        $now = new \DateTimeImmutable('2026-06-01 09:00');
        $d = $this->deadline('2026-06-02', completed: true); // +1 but completed

        $this->service()->processAlerts($now);

        $this->assertCount(0, $this->eventsFor($d));
    }

    /**
     * A three-year window makes a first warning at seven days useless, so the general
     * limitation is announced at 30 and 14 days. The tier fires once and marks every
     * looser tier with it, which is what stops the 7/3/1 ladder from re-announcing the
     * same term on the way down.
     */
    public function testTheGeneralLimitationIsAnnouncedThirtyDaysAhead(): void
    {
        $now = new \DateTimeImmutable('2026-06-01 09:00');
        $limitation = $this->deadline('2026-06-29', type: DeadlineType::PRESCRIPTIE); // +28
        $hearing = $this->deadline('2026-06-29'); // +28, procedural: nothing yet

        $report = $this->service()->processAlerts($now);
        $this->em->clear();
        $repo = $this->em->getRepository(LegalDeadline::class);

        $this->assertCount(1, $this->eventsFor($limitation));
        $this->assertSame(1, $report->sentLongRange);
        $this->assertCount(0, $this->eventsFor($hearing), 'A procedural term keeps the 7/3/1 ladder.');

        $stored = $repo->find($limitation->getId());
        $this->assertTrue($stored->isAlertSentLongRange());
        $this->assertFalse($stored->isAlertSentMidRange(), 'The 14-day tier is still ahead.');
    }

    /**
     * The six months that keep the interruption alive are shorter than three years and
     * the act they ask for takes longer to prepare, so they are announced earlier still,
     * at 60 and 30 days.
     */
    public function testTheSixMonthTermIsAnnouncedSixtyDaysAhead(): void
    {
        $now = new \DateTimeImmutable('2026-06-01 09:00');
        $filing = $this->deadline('2026-07-26', type: DeadlineType::DEPUNERE_CERERE); // +55
        $limitation = $this->deadline('2026-07-26', type: DeadlineType::PRESCRIPTIE); // +55, outside its 30

        $this->service()->processAlerts($now);

        $this->assertCount(1, $this->eventsFor($filing));
        $this->assertCount(0, $this->eventsFor($limitation), 'The general limitation only starts at 30 days.');
    }

    /**
     * A term first seen inside a tight tier must not emit the looser ones afterwards:
     * firing a tier marks it and every looser tier at once.
     */
    public function testATightTierSuppressesTheLooserOnesOnALimitationTerm(): void
    {
        $limitation = $this->deadline('2026-06-04', type: DeadlineType::PRESCRIPTIE); // +3

        $this->service()->processAlerts(new \DateTimeImmutable('2026-06-01 09:00'));
        $this->service()->processAlerts(new \DateTimeImmutable('2026-06-02 09:00'));

        $this->assertCount(1, $this->eventsFor($limitation), 'One alert only, the tightest tier that was due.');

        $this->em->clear();
        $stored = $this->em->getRepository(LegalDeadline::class)->find($limitation->getId());
        $this->assertTrue($stored->isAlertSentLongRange());
        $this->assertTrue($stored->isAlertSentMidRange());
        $this->assertTrue($stored->isAlertSent7());
        $this->assertTrue($stored->isAlertSent3());
    }

    /** Moving a date must re-open every tier, the long-range ones included. */
    public function testResettingTheFlagsClearsTheLongRangeTiersToo(): void
    {
        $deadline = $this->deadline('2026-06-29', type: DeadlineType::PRESCRIPTIE);

        $this->service()->processAlerts(new \DateTimeImmutable('2026-06-01 09:00'));
        $this->em->clear();

        $stored = $this->em->getRepository(LegalDeadline::class)->find($deadline->getId());
        $this->assertTrue($stored->isAlertSentLongRange());

        $stored->resetAlertFlags();
        $this->assertFalse($stored->isAlertSentLongRange());
        $this->assertFalse($stored->isAlertSentMidRange());
    }

    /**
     * The enforcement request is filed and the bailiff has not sent back the number under
     * which he registered it. The act the term asks for has been performed, so warning
     * about the term would be nagging about something done; what is missing is the
     * confirmation, and that is chased once, by BlockedCaseAlertService. The term stays
     * open because the interruption of CPC art. 708 para. 1 pt. 2 rests on a filing that
     * is proven, not declared.
     */
    public function testTheEnforcementLimitationGoesSilentWhileTheRegistrationNumberIsAwaited(): void
    {
        $this->case->setStatus(CaseStatus::EXECUTARE);
        $this->case->setEnforcementRequestDate(new \DateTimeImmutable('2026-08-01'));
        $this->em->flush();

        $deadline = $this->deadline('2026-09-01', type: DeadlineType::PRESCRIPTIE_EXECUTARE);
        $service = $this->service();

        $service->processAlerts(new \DateTimeImmutable('2026-08-29'));

        self::assertTrue($service->alertsMuted($deadline));
        self::assertSame([], $this->eventsFor($deadline), 'A muted term dispatches nothing.');
        self::assertSame([], $service->alertLadder($deadline), 'The card must not promise reminders that will not go out.');
        self::assertNull($service->nextAlertDaysBefore($deadline));
    }

    /** With the number recorded the term is closed anyway, so nothing here is muted. */
    public function testTheEnforcementLimitationAlertsNormallyWithoutADeclaredFiling(): void
    {
        $this->case->setStatus(CaseStatus::EXECUTARE);
        $this->em->flush();

        $deadline = $this->deadline('2026-09-01', type: DeadlineType::PRESCRIPTIE_EXECUTARE);
        $service = $this->service();

        self::assertFalse($service->alertsMuted($deadline));
        self::assertSame(7, $service->nextAlertDaysBefore($deadline));
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE ld FROM legal_deadline ld JOIN legal_case lc ON ld.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
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
