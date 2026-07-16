<?php

declare(strict_types=1);

namespace App\Tests\Service\Portal;

use App\Entity\AuditLog;
use App\Entity\Court;
use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Entity\Notification;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\CourtType;
use App\Enum\DeadlineType;
use App\Enum\NotificationType;
use App\Enum\PortalEventType;
use App\Repository\LegalDeadlineRepository;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Deadline\DeadlineService;
use App\Service\Deadline\WorkingDayResolver;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use App\Service\Portal\MonitoringEventApplier;
use App\Service\Portal\RulingProposalResolver;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class MonitoringEventApplierTest extends KernelTestCase
{
    use CountyFixtureTrait;

    private EntityManagerInterface $em;
    private MonitoringEventApplier $applier;
    private LegalDeadlineRepository $deadlines;
    private User $user;
    private Court $court;
    private string $testPrefix;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->applier = static::getContainer()->get(MonitoringEventApplier::class);
        $this->deadlines = static::getContainer()->get(LegalDeadlineRepository::class);
        $this->testPrefix = 'portal-apply-' . uniqid();

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail($this->testPrefix . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'test'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);

        $this->court = new Court();
        $this->court->setName('Applier Court ' . $this->testPrefix);
        $this->court->setCounty($this->createCounty($this->em, 'CJ'));
        $this->court->setType(CourtType::JUDECATORIE);
        $this->court->setPortalCode('ApplierCourt' . uniqid());
        $this->em->persist($this->court);

        $this->em->flush();
    }

    private function createCase(CaseStatus $status): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCourt($this->court);
        $case->setStatus($status);
        $case->setCourtCaseNumber('500/211/2026');
        $case->setPortalMonitoringActive(true);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    private function event(LegalCase $case, PortalEventType $type, ?\DateTimeInterface $date, ?string $solutie = null, ?string $solutieSumar = null): CourtPortalEvent
    {
        $event = new CourtPortalEvent();
        $event->setLegalCase($case);
        $event->setEventType($type);
        $event->setEventDate($date);
        $event->setDescription('test event ' . $type->value);
        $event->setSolutie($solutie);
        // Mirror the portal shape: when no explicit summary is given, reuse the
        // tip. Pass a distinct $solutieSumar to exercise the free-text body.
        $event->setSolutieSumar($solutieSumar ?? $solutie);
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function testHearingScheduledAppliesFixeazaTermenAndCreatesDeadline(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $event = $this->event($case, PortalEventType::HEARING_SCHEDULED, new \DateTime('2026-09-15'));

        $this->applier->applyEvents($case, [$event]);

        $this->assertSame(CaseStatus::TERMEN_FIXAT, $case->getStatus());
        $this->assertNotNull(
            $this->deadlines->findOneByCaseAndType($case, DeadlineType::JUDECATA),
            'A JUDECATA deadline should be created from the portal hearing.',
        );
    }

    public function testHearingCompletedIsProposalOnlyAndDoesNotChangeStatus(): void
    {
        $case = $this->createCase(CaseStatus::TERMEN_FIXAT);
        $event = $this->event(
            $case,
            PortalEventType::HEARING_COMPLETED,
            new \DateTime('2026-09-20'),
            'Admite cererea. Emite ordonanța de plată.',
        );

        $this->applier->applyEvents($case, [$event]);

        // Conservator: NU se aplică emite_ordonanta automat din text liber.
        $this->assertSame(CaseStatus::TERMEN_FIXAT, $case->getStatus());

        $proposals = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
            'action' => 'portal_transition_proposed',
        ]);
        $this->assertNotEmpty($proposals);
        $this->assertSame(
            CaseTransition::EMITE_ORDONANTA->value,
            $proposals[0]->getNewData()['suggestedTransition'],
        );
    }

    public function testRulingIssuedIsAlsoProposalOnly(): void
    {
        // RULING_ISSUED împarte handler-ul cu HEARING_COMPLETED → tot propunere.
        $case = $this->createCase(CaseStatus::TERMEN_FIXAT);
        $event = $this->event(
            $case,
            PortalEventType::RULING_ISSUED,
            new \DateTime('2026-09-21'),
            'Respinge cererea ca neîntemeiată.',
        );

        $this->applier->applyEvents($case, [$event]);

        $this->assertSame(CaseStatus::TERMEN_FIXAT, $case->getStatus());

        $proposals = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
            'action' => 'portal_transition_proposed',
        ]);
        $this->assertNotEmpty($proposals);
        $this->assertSame(
            CaseTransition::RESPINGE->value,
            $proposals[0]->getNewData()['suggestedTransition'],
        );
    }

    public function testRejectionMentioningOrdonantaSuggestsRespingeNotEmite(): void
    {
        // Real portal.just.ro text (dosar 2001/300/2026): a rejection whose
        // wording contains "ordonanței de plată" (the object of every OP case).
        // The suggestion must be RESPINGE, never EMITE_ORDONANTA.
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $event = $this->event(
            $case,
            PortalEventType::HEARING_COMPLETED,
            new \DateTime('2026-05-28'),
            'Respinge cererea de emitere a ordonanței de plată, ca neîntemeiată.',
        );

        $this->applier->applyEvents($case, [$event]);

        $this->assertSame(CaseStatus::DOSAR_INREGISTRAT, $case->getStatus());

        $proposals = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
            'action' => 'portal_transition_proposed',
        ]);
        $this->assertNotEmpty($proposals);
        $this->assertSame(
            CaseTransition::RESPINGE->value,
            $proposals[0]->getNewData()['suggestedTransition'],
        );
    }

    public function testAdmissionWithSecondaryRespingeInBodySuggestsEmiteNotRespinge(): void
    {
        // Real portal.just.ro shape (dosar 11671/300/2024): the "tip soluție"
        // field reads "Admite cererea", but the free-text body rejects an
        // ancillary request ("Respinge cererea pârâtului de eșalonare..."). The
        // suggestion must follow the controlled tip (EMITE_ORDONANTA), not the
        // secondary "respinge" buried in the body.
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $event = $this->event(
            $case,
            PortalEventType::HEARING_COMPLETED,
            new \DateTime('2024-11-15'),
            'Admite cererea',
            'Admite cererea de chemare în judecată. Obligă pârâtul la plata sumei de '
                . '143.718 lei. Respinge cererea pârâtului de eșalonare a debitului, ca neîntemeiată.',
        );

        $this->applier->applyEvents($case, [$event]);

        $this->assertSame(CaseStatus::DOSAR_INREGISTRAT, $case->getStatus());

        $proposals = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
            'action' => 'portal_transition_proposed',
        ]);
        $this->assertNotEmpty($proposals);
        $this->assertSame(
            CaseTransition::EMITE_ORDONANTA->value,
            $proposals[0]->getNewData()['suggestedTransition'],
        );
    }

    public function testInAnulareAnuleazaSuggestsAdmiteCerereAnulareNotRespinge(): void
    {
        // In IN_ANULARE, "Anulează ordonanța de plată" = cererea în anulare
        // ADMITED (OP dissolved). Must suggest ADMITE_CERERE_ANULARE, never the
        // rejection branch (CPC art. 1024).
        $case = $this->createCase(CaseStatus::IN_ANULARE);
        $event = $this->event(
            $case,
            PortalEventType::HEARING_COMPLETED,
            new \DateTime('2026-07-10'),
            'Admite cererea în anulare. Anulează ordonanța de plată nr. 8730/2026.',
        );

        $this->applier->applyEvents($case, [$event]);

        $proposals = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
            'action' => 'portal_transition_proposed',
        ]);
        $this->assertNotEmpty($proposals);
        $this->assertSame(
            CaseTransition::ADMITE_CERERE_ANULARE->value,
            $proposals[0]->getNewData()['suggestedTransition'],
        );
    }

    public function testInAnulareRespingereSuggestsRespingeCerereAnulare(): void
    {
        $case = $this->createCase(CaseStatus::IN_ANULARE);
        $event = $this->event(
            $case,
            PortalEventType::HEARING_COMPLETED,
            new \DateTime('2026-07-11'),
            'Respinge cererea în anulare, ca neîntemeiată.',
        );

        $this->applier->applyEvents($case, [$event]);

        $proposals = $this->em->getRepository(AuditLog::class)->findBy([
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
            'action' => 'portal_transition_proposed',
        ]);
        $this->assertNotEmpty($proposals);
        $this->assertSame(
            CaseTransition::RESPINGE_CERERE_ANULARE->value,
            $proposals[0]->getNewData()['suggestedTransition'],
        );
    }

    public function testAppealFiledAppliesFormuleazaCerereAnulare(): void
    {
        $case = $this->createCase(CaseStatus::ORDONANTA_EMISA);
        $event = $this->event($case, PortalEventType::APPEAL_FILED, new \DateTime('2026-09-25'));

        $this->applier->applyEvents($case, [$event]);

        $this->assertSame(CaseStatus::IN_ANULARE, $case->getStatus());
    }

    public function testHearingScheduledSkipsTransitionWhenNotAvailableButStillCreatesDeadline(): void
    {
        // CERERE_DEPUSA → fixeaza_termen indisponibil; termenul JUDECATA se creează totuși.
        $case = $this->createCase(CaseStatus::CERERE_DEPUSA);
        $event = $this->event($case, PortalEventType::HEARING_SCHEDULED, new \DateTime('2026-10-01'));

        $this->applier->applyEvents($case, [$event]);

        $this->assertSame(CaseStatus::CERERE_DEPUSA, $case->getStatus());
        $this->assertNotNull($this->deadlines->findOneByCaseAndType($case, DeadlineType::JUDECATA));
    }

    public function testCaseInfoUpdateDoesNothing(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $event = $this->event($case, PortalEventType::CASE_INFO_UPDATE, null);

        $this->applier->applyEvents($case, [$event]);

        $this->assertSame(CaseStatus::DOSAR_INREGISTRAT, $case->getStatus());
        $this->assertNull($this->deadlines->findOneByCaseAndType($case, DeadlineType::JUDECATA));
    }

    public function testHearingScheduledWithoutDateIsSkippedGracefully(): void
    {
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $event = $this->event($case, PortalEventType::HEARING_SCHEDULED, null);

        // Nu trebuie să arunce; statusul rămâne neschimbat fiindcă fără dată
        // nu se poate crea termenul (early return).
        $this->applier->applyEvents($case, [$event]);

        $this->assertSame(CaseStatus::DOSAR_INREGISTRAT, $case->getStatus());
        $this->assertNull($this->deadlines->findOneByCaseAndType($case, DeadlineType::JUDECATA));
    }

    /**
     * Builds an applier whose only substituted dependency is the notification
     * dispatcher, replaced by a spy that records every {@see NotificationDispatch}
     * into the given by-reference array. All other collaborators come from the
     * real container. Manual construction is required because the applier holds
     * the dispatcher as a readonly property (not swappable via reflection).
     *
     * @param list<NotificationDispatch> $captured
     */
    private function applierWithSpyDispatcher(array &$captured): MonitoringEventApplier
    {
        $spy = $this->createMock(NotificationDispatcherInterface::class);
        $spy->method('dispatch')->willReturnCallback(
            static function (NotificationDispatch $request) use (&$captured): void {
                $captured[] = $request;
            },
        );

        $c = static::getContainer();

        return new MonitoringEventApplier(
            $c->get(CaseWorkflowService::class),
            $c->get(DeadlineService::class),
            $c->get(LegalDeadlineRepository::class),
            $c->get(WorkingDayResolver::class),
            $c->get(AuditLogService::class),
            $c->get(RulingProposalResolver::class),
            $spy,
            $c->get(TranslatorInterface::class),
            $c->get(UrlGeneratorInterface::class),
            $this->em,
        );
    }

    public function testRulingProposalDispatchesRulingConfirmationNotification(): void
    {
        // A sensitive ruling (RULING_ISSUED) stays proposal-only for the workflow,
        // but it must also notify the lawyer that a portal ruling needs manual
        // confirmation of the new case status (UI Pas 7.2).
        $case = $this->createCase(CaseStatus::TERMEN_FIXAT);
        $event = $this->event(
            $case,
            PortalEventType::RULING_ISSUED,
            new \DateTime('2026-09-21'),
            'Respinge cererea ca neîntemeiată.',
        );

        $captured = [];
        $applier = $this->applierWithSpyDispatcher($captured);
        $applier->applyEvents($case, [$event]);

        $confirmations = [];
        foreach ($captured as $dispatch) {
            if ($dispatch->type === NotificationType::PORTAL_RULING_CONFIRMATION) {
                $confirmations[] = $dispatch;
            }
        }

        $this->assertCount(1, $confirmations);
        $dispatch = $confirmations[0];
        $this->assertSame($this->user->getId(), $dispatch->user->getId());
        $this->assertSame($case->getId(), $dispatch->legalCase?->getId());
        $this->assertSame(
            sprintf('portal_ruling:%d:%d', $case->getId(), $event->getId()),
            $dispatch->dedupKey,
        );
        $this->assertSame('warning', $dispatch->variant);
    }

    public function testSafeAutoTransitionDoesNotDispatchRulingConfirmation(): void
    {
        // HEARING_SCHEDULED is a safe AUTO transition (fixeaza_termen). It must not
        // ask the lawyer to confirm a ruling: no PORTAL_RULING_CONFIRMATION here.
        $case = $this->createCase(CaseStatus::DOSAR_INREGISTRAT);
        $event = $this->event($case, PortalEventType::HEARING_SCHEDULED, new \DateTime('2026-09-15'));

        $captured = [];
        $applier = $this->applierWithSpyDispatcher($captured);
        $applier->applyEvents($case, [$event]);

        $this->assertSame(CaseStatus::TERMEN_FIXAT, $case->getStatus());

        $confirmations = [];
        foreach ($captured as $dispatch) {
            if ($dispatch->type === NotificationType::PORTAL_RULING_CONFIRMATION) {
                $confirmations[] = $dispatch;
            }
        }
        $this->assertCount(0, $confirmations);
    }

    public function testRulingConfirmationPersistsDurableRowOnceAcrossReplay(): void
    {
        // Durable + dedup: the real dispatcher persists exactly one in-app row for
        // the ruling confirmation, and a replay (cron re-run) does not double it.
        $case = $this->createCase(CaseStatus::TERMEN_FIXAT);
        $event = $this->event(
            $case,
            PortalEventType::HEARING_COMPLETED,
            new \DateTime('2026-09-20'),
            'Admite cererea. Emite ordonanța de plată.',
        );

        $expectedDedupKey = sprintf('portal_ruling:%d:%d', $case->getId(), $event->getId());

        $this->applier->applyEvents($case, [$event]);
        // Replay the same event: the dedup guard must prevent a second row.
        $this->applier->applyEvents($case, [$event]);

        $rows = $this->em->getRepository(Notification::class)->findBy([
            'legalCase' => $case,
            'type' => NotificationType::PORTAL_RULING_CONFIRMATION->value,
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame($expectedDedupKey, $rows[0]->getDedupKey());
        $this->assertSame($this->user->getId(), $rows[0]->getUser()->getId());
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE al FROM audit_log al WHERE al.entity_id IN (SELECT lc.id FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?)",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE al FROM audit_log al JOIN legal_deadline ld ON al.entity_id = ld.id AND al.entity_type = 'App\\\\Entity\\\\LegalDeadline' JOIN legal_case lc ON ld.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE ld FROM legal_deadline ld JOIN legal_case lc ON ld.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE cpe FROM court_portal_event cpe JOIN legal_case lc ON cpe.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        // Status transitions applied here now fire case_status notifications
        // (linked to the case) via EmailNotificationSubscriber; clear them before
        // the case FK is removed.
        $conn->executeStatement(
            "DELETE n FROM notification n JOIN user u ON n.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement(
            "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE ?", ['%' . $this->testPrefix . '%']);
        parent::tearDown();
    }
}
