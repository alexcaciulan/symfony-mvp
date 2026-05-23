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

    private function deadline(string $date, bool $completed = false, ?callable $flags = null): LegalDeadline
    {
        $d = new LegalDeadline();
        $d->setLegalCase($this->case);
        $d->setType(DeadlineType::JUDECATA);
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
