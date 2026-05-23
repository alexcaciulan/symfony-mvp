<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\CaseStatus;
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

    public function testSomatieTrimisaCreatesPaymentNoticeDeadlineWithDisclaimer(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2026-02-02')); // luni
        $this->em->flush();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::RASPUNS_SOMATIE,
        ]);

        self::assertCount(1, $deadlines, 'Tranziția trimite_somatie trebuie să creeze RASPUNS_SOMATIE.');
        // 2026-02-02 luni + 15 zile = 2026-02-17 marți (zi lucrătoare → fără prorogare)
        self::assertSame('2026-02-17', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
        // B1 legal: disclaimer obligatoriu pentru a avertiza avocatul că data
        // calculată e de la generare PDF, NU de la primirea de către debitor.
        self::assertStringContainsString('Termen estimativ', (string) $deadlines[0]->getDescription());
        self::assertStringContainsString('CPC art. 1015', (string) $deadlines[0]->getDescription());
    }

    public function testSomatieTrimisaSkipsWhenPaymentNoticeDateNull(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        // NU setăm paymentNoticeDate

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::RASPUNS_SOMATIE,
        ]);

        self::assertCount(0, $deadlines, 'Fără paymentNoticeDate, RASPUNS_SOMATIE nu trebuie creat.');
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
        // 2026-02-02 luni + 10 zile = 2026-02-12 joi (zi lucrătoare → fără prorogare)
        self::assertSame('2026-02-12', $deadlines[0]->getDeadlineDate()->format('Y-m-d'));
    }

    public function testIdempotencyOnDuplicateWorkflowFire(): void
    {
        $case = $this->newCase(new \DateTime('2024-03-15'));
        $case->setPaymentNoticeDate(new \DateTime('2026-02-02'));
        $this->em->flush();

        $this->workflowService->apply($case, 'trimite_somatie');
        $this->em->flush();

        // Re-fire-uim subscriber-ul direct cu o instanță manuală a evenimentului
        // (Symfony Workflow nu permite re-apply pe aceeași tranziție natural).
        // Verificăm că un al doilea apel la subscriber nu duplică termenul.
        /** @var DeadlineCreationSubscriber $subscriber */
        $subscriber = static::getContainer()->get(DeadlineCreationSubscriber::class);
        /** @var WorkflowInterface $workflow */
        $workflow = static::getContainer()->get('state_machine.legal_case');
        $marking = $workflow->getMarking($case);

        // Simulare manuală: a doua execuție onSomatieTrimisa via API publică.
        // Pasăm `null` pentru transition — EnteredEvent îl acceptă în Symfony 7+.
        $event = new EnteredEvent($case, $marking, null, $workflow);
        $subscriber->onSomatieTrimisa($event);
        $this->em->flush();

        $deadlines = $this->em->getRepository(LegalDeadline::class)->findBy([
            'legalCase' => $case->getId(),
            'type' => DeadlineType::RASPUNS_SOMATIE,
        ]);
        self::assertCount(1, $deadlines, 'Apel dublu pe subscriber → idempotency trebuie să prevină duplicat.');
    }

    public function testFullCascadeCreatesThreeDeadlines(): void
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

        $types = array_map(static fn (LegalDeadline $d): DeadlineType => $d->getType(), $deadlines);
        self::assertCount(3, $deadlines);
        self::assertContains(DeadlineType::PRESCRIPTIE, $types);
        self::assertContains(DeadlineType::RASPUNS_SOMATIE, $types);
        self::assertContains(DeadlineType::CERERE_IN_ANULARE, $types);
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
