<?php

declare(strict_types=1);

namespace App\Tests\Service\Portal;

use App\Entity\AuditLog;
use App\Entity\Court;
use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\CourtType;
use App\Enum\DeadlineType;
use App\Enum\PortalEventType;
use App\Repository\LegalDeadlineRepository;
use App\Service\Portal\MonitoringEventApplier;
use App\Tests\Support\CountyFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

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
        $conn->executeStatement(
            "DELETE lc FROM legal_case lc JOIN user u ON lc.user_id = u.id WHERE u.email LIKE ?",
            [$this->testPrefix . '%'],
        );
        $conn->executeStatement("DELETE FROM user WHERE email LIKE ?", [$this->testPrefix . '%']);
        $conn->executeStatement("DELETE FROM court WHERE name LIKE ?", ['%' . $this->testPrefix . '%']);
        parent::tearDown();
    }
}
