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
 * Auto-creates procedural deadlines through DeadlineService in reaction to workflow
 * events and to the Doctrine `postPersist` of LegalCase. Idempotent through
 * `findOneByCaseAndType()`. Exceptions raised by DeadlineService are logged, never
 * propagated, so they cannot break a flush or a workflow apply.
 *
 * No listener creates the RASPUNS_SOMATIE term. The fifteen days of CPC art. 1015
 * para. 1 run from the debtor RECEIVING the summons, and at SOMATIE_TRIMISA only the
 * generation date is known, which is a different date with no legal meaning. The term
 * is therefore born when the lawyer records the real communication date
 * ({@see DeadlineService::recalculatePaymentNoticeDeadline()}, which creates it when
 * it is missing); until then the case is carried by the blockage list, which asks for
 * exactly that date.
 */
#[AsDoctrineListener(event: Events::postPersist)]
final class DeadlineCreationSubscriber
{
    public function __construct(
        private readonly DeadlineService $deadlineService,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * The request reached the court, which is the condition NCC art. 2540 sets for the
     * interruption produced by the summons to hold, so the six-month term has been met.
     *
     * Listened for on both places on purpose. A case can reach the court without the
     * lawyer confirming it: the portal surfaces the ECRIS number and `inregistreaza_dosar`
     * runs straight from CERERE_GENERATA, skipping CERERE_DEPUSA. A dosar on the portal
     * is itself proof the request arrived, so the term closes there too. Closing is
     * idempotent, so the two paths cannot double-apply.
     */
    #[AsEventListener(event: 'workflow.legal_case.entered.CERERE_DEPUSA')]
    #[AsEventListener(event: 'workflow.legal_case.entered.DOSAR_INREGISTRAT')]
    public function onRequestReachedCourt(EnteredEvent $event): void
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
     * Enforcement started, so the enforcement-limitation term is CLOSED here, not
     * created. That term measures the window in which the right to ask for enforcement
     * is still alive (CPC art. 705 para. 1); the request filed with the bailiff
     * interrupts it (CPC art. 708 para. 1 pt. 2).
     *
     * What closes the term is the REGISTRATION NUMBER the bailiff assigned to that
     * request, not the transition and not the date alone. The transition is a status the
     * lawyer declares and the date is a declaration too; the number comes back from the
     * bailiff, who registers the request on receipt, and is therefore the confirmation
     * the closing rests on. On a term whose expiry extinguishes the right to enforce, a
     * term left open costs an alert too many, and a term closed on a declaration costs
     * the client the title.
     *
     * The date still decides WHEN the term is closed as of, because the interruption of
     * CPC art. 708 para. 1 pt. 2 attaches to the request filed and runs from its date.
     * With the date recorded and the number still missing, the term stays open but stops
     * alerting ({@see \App\Service\Deadline\DeadlineAlertService}): the act was performed
     * and only its confirmation is awaited.
     *
     * Cases that reach EXECUTARE straight from ORDONANTA_EMISA or IN_ANULARE never had
     * the term created (only DEFINITIVA creates it) and need none: closing is a no-op
     * for them, which is the correct outcome rather than a gap.
     */
    #[AsEventListener(event: 'workflow.legal_case.entered.EXECUTARE')]
    public function onExecutare(EnteredEvent $event): void
    {
        $case = $event->getSubject();
        if (!$case instanceof LegalCase || $case->getId() === null) {
            return;
        }

        $enforcementRequestDate = $case->getEnforcementRequestDate();
        $registrationNumber = $case->getEnforcementRegistrationNumber();
        if ($enforcementRequestDate === null || $registrationNumber === null || $registrationNumber === '') {
            $this->logger->info('Keeping PRESCRIPTIE_EXECUTARE deadline open: the bailiff registration number of the enforcement request is not recorded', [
                'caseNumber' => $case->getCaseNumber(),
                'hasRequestDate' => $enforcementRequestDate !== null,
            ]);

            return;
        }

        try {
            $this->deadlineService->closeExecutionPrescriptionDeadline($case, $enforcementRequestDate, $registrationNumber);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to close PRESCRIPTIE_EXECUTARE deadline', [
                'caseNumber' => $case->getCaseNumber(),
                'exception' => $e->getMessage(),
            ]);
        }
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
        //  0. the communication of the ruling given on the annulment request, when the
        //     debtor filed one. The order does NOT become final on the lapse of the
        //     ten days in that case: it becomes final when the annulment request is
        //     rejected (CPC art. 1024 para. 8), and the three years then run from the
        //     communication of THAT ruling. The date is not derivable, so it is the
        //     one the lawyer records on the case; until then the case is listed as a
        //     blockage (DeadlineBlockageReason::ANNULMENT_RULING_COMMUNICATION_MISSING)
        //     and the branches below are not used, because they would anchor on the
        //     first ruling and produce a term that expires too early;
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
        $annulmentCommunicationDate = $case->getAnnulmentRulingCommunicationDate();
        $communicationDate = $case->getRulingCommunicationDate();
        $finalRulingDate = $case->getFinalRulingDate();

        if ($annulmentCommunicationDate !== null) {
            $definitiveDate = $annulmentCommunicationDate;
        } elseif ($case->hasPassedThroughAnnulment()) {
            $this->logger->info('Skipping PRESCRIPTIE_EXECUTARE deadline: the annulment ruling communication date is not recorded yet', [
                'caseNumber' => $case->getCaseNumber(),
            ]);

            return;
        } elseif ($communicationDate !== null) {
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
