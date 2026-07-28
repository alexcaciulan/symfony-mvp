<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\LegalCase;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use App\Service\Deadline\DeadlineService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\EnteredEvent;

/**
 * Pas 4.2 — auto-creează termene procedurale via DeadlineService în reacție la
 * workflow events + Doctrine `postPersist` LegalCase. Idempotency prin
 * `findOneByCaseAndType()`. Excepțiile DeadlineService sunt log-uite, NU
 * propagate (NU rupe flush/apply).
 */
#[AsDoctrineListener(event: Events::postPersist)]
final class DeadlineCreationSubscriber
{
    /**
     * Avertizare juridică obligatorie pe `LegalDeadline.description` pentru
     * RASPUNS_SOMATIE: termenul calculat e estimat (de la data generării PDF),
     * NU de la data primirii efective de către debitor — CPC art. 1015 alin. 1
     * spune că cele 15 zile curg de la PRIMIRE. Avocatul trebuie să ajusteze
     * manual data când are dovada comunicării.
     */
    private const PAYMENT_NOTICE_DEADLINE_DISCLAIMER = 'Termen estimativ. Calculat de la data generării somației. Actualizați după confirmarea primirii de către debitor (CPC art. 1015 alin. 1 — termenul curge de la primire).';

    public function __construct(
        private readonly DeadlineService $deadlineService,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    #[AsEventListener(event: 'workflow.legal_case.entered.SOMATIE_TRIMISA')]
    public function onSomatieTrimisa(EnteredEvent $event): void
    {
        $case = $event->getSubject();
        if (!$case instanceof LegalCase || $case->getId() === null) {
            return;
        }

        if ($this->hasDeadline($case, DeadlineType::RASPUNS_SOMATIE)) {
            return;
        }

        $paymentNoticeDate = $case->getPaymentNoticeDate();
        if ($paymentNoticeDate === null) {
            // Eroare de ordonare: CaseSummonsController setează paymentNoticeDate
            // ÎNAINTE de workflow apply. Dacă e null aici → bug în caller.
            $this->logger->error('Skipping RASPUNS_SOMATIE deadline: paymentNoticeDate not set on workflow entered', [
                'caseNumber' => $case->getCaseNumber(),
            ]);

            return;
        }

        try {
            $deadline = $this->deadlineService->createPaymentNoticeDeadline(
                $case,
                \DateTimeImmutable::createFromInterface($paymentNoticeDate),
            );
            $deadline->setDescription(self::PAYMENT_NOTICE_DEADLINE_DISCLAIMER);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create RASPUNS_SOMATIE deadline', [
                'caseNumber' => $case->getCaseNumber(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The request reached the court, which is the condition NCC art. 2540 sets for the
     * interruption produced by the summons to hold, so the six-month term has been met.
     */
    #[AsEventListener(event: 'workflow.legal_case.entered.CERERE_DEPUSA')]
    public function onCerereDepusa(EnteredEvent $event): void
    {
        $case = $event->getSubject();
        if (!$case instanceof LegalCase || $case->getId() === null) {
            return;
        }

        try {
            $this->deadlineService->closeFilingDeadline($case);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to close DEPUNERE_CERERE deadline', [
                'caseNumber' => $case->getCaseNumber(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    #[AsEventListener(event: 'workflow.legal_case.entered.ORDONANTA_EMISA')]
    public function onOrdonantaEmisa(EnteredEvent $event): void
    {
        $case = $event->getSubject();
        if (!$case instanceof LegalCase || $case->getId() === null) {
            return;
        }

        if ($this->hasDeadline($case, DeadlineType::CERERE_IN_ANULARE)) {
            return;
        }

        $communicationDate = $case->getRulingCommunicationDate();
        if ($communicationDate === null) {
            // Comportament normal: la tranziție ORDONANTA_EMISA, avocatul încă
            // NU are data comunicării (ordonanța se comunică ulterior).
            $this->logger->info('Skipping CERERE_IN_ANULARE deadline: rulingCommunicationDate not set yet', [
                'caseNumber' => $case->getCaseNumber(),
            ]);

            return;
        }

        try {
            $this->deadlineService->createAppealDeadline($case, $communicationDate);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create CERERE_IN_ANULARE deadline', [
                'caseNumber' => $case->getCaseNumber(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    #[AsEventListener(event: 'workflow.legal_case.entered.DEFINITIVA')]
    public function onDefinitiva(EnteredEvent $event): void
    {
        $case = $event->getSubject();
        if (!$case instanceof LegalCase || $case->getId() === null) {
            return;
        }

        $this->ensureExecutionPrescriptionDeadline($case);
    }

    /**
     * Enforcement can also start directly from ORDONANTA_EMISA / IN_ANULARE (the
     * order is enforceable from communication, CPC art. 1021), bypassing DEFINITIVA.
     * Guarantee the PRESCRIPTIE_EXECUTARE deadline exists in that case too. Idempotent
     * via hasDeadline(), so a case that reached EXECUTARE through DEFINITIVA (where
     * onDefinitiva already created it) is a no-op here.
     */
    #[AsEventListener(event: 'workflow.legal_case.entered.EXECUTARE')]
    public function onExecutare(EnteredEvent $event): void
    {
        $case = $event->getSubject();
        if (!$case instanceof LegalCase || $case->getId() === null) {
            return;
        }

        $this->ensureExecutionPrescriptionDeadline($case);
    }

    private function ensureExecutionPrescriptionDeadline(LegalCase $case): void
    {
        if ($this->hasDeadline($case, DeadlineType::PRESCRIPTIE_EXECUTARE)) {
            return;
        }

        // Enforcement prescription (CPC art. 705) runs from when the order became
        // enforceable, and the anchor is picked in the order that keeps the error on
        // the safe side, an alert that is early rather than a deadline that is later
        // than the real one:
        //  1. the communication date. For court rulings the three years run from the
        //     day the ruling became final (CPC art. 705 para. 2), and the order becomes
        //     final the day AFTER the annulment term lapses, so the anchor is that
        //     term's maturity date plus one day, taken from DeadlineService so it never
        //     drifts from the deadline the lawyer sees (free days per CPC art. 181
        //     para. 1 pt. 2, plus the working-day prorogation of para. 2). A payment order
        //     is enforceable from service even while under appeal (CPC art. 1021), but
        //     that governs when enforcement may start, not when the limitation begins:
        //     art. 705 para. 2 ties the latter to the ruling becoming final;
        //  2. failing that, the ruling date. Pronouncement always precedes service, so
        //     the term computed from it expires before the real one and warns early;
        //  3. failing both, nothing is created. The case surfaces in the blockage zone
        //     of the agenda instead (DeadlineBlockageReason::EXECUTION_ANCHOR_MISSING).
        //     The current day must never be used here: it is later than the real
        //     anchor, so it would show a term longer than the one that actually runs,
        //     which is false safety on an irreversible deadline.
        $communicationDate = $case->getRulingCommunicationDate();
        $finalRulingDate = $case->getFinalRulingDate();

        if ($communicationDate !== null) {
            $definitiveDate = $this->deadlineService->appealTermEnd($communicationDate)->modify('+1 day');
        } elseif ($finalRulingDate !== null) {
            $definitiveDate = \DateTimeImmutable::createFromInterface($finalRulingDate);
        } else {
            $this->logger->warning('Skipping PRESCRIPTIE_EXECUTARE deadline: neither rulingCommunicationDate nor finalRulingDate is known', [
                'caseNumber' => $case->getCaseNumber(),
            ]);

            return;
        }

        try {
            $this->deadlineService->createExecutionPrescriptionDeadline($case, $definitiveDate);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create PRESCRIPTIE_EXECUTARE deadline', [
                'caseNumber' => $case->getCaseNumber(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof LegalCase) {
            return;
        }

        // One PRESCRIPTIE deadline per distinct position due date (NCC art. 2517):
        // the case due date is only the earliest of them, so a single deadline
        // pinned to it would leave the later invoices unmonitored. The service is
        // idempotent per due date, so a duplicate fire is a no-op.
        try {
            $created = $this->deadlineService->createPrescriptionDeadlines($entity);
            if ($created === []) {
                $this->logger->info('Skipping PRESCRIPTIE deadline: no due date on positions or case', [
                    'caseNumber' => $entity->getCaseNumber(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create PRESCRIPTIE deadline', [
                'caseNumber' => $entity->getCaseNumber(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function hasDeadline(LegalCase $case, DeadlineType $type): bool
    {
        return $this->deadlineRepository->findOneByCaseAndType($case, $type) !== null;
    }
}
