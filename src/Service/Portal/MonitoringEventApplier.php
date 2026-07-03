<?php

declare(strict_types=1);

namespace App\Service\Portal;

use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Enum\CaseTransition;
use App\Enum\DeadlineType;
use App\Enum\PortalEventType;
use App\Repository\LegalDeadlineRepository;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Deadline\DeadlineService;
use App\Service\Deadline\WorkingDayResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Pas 6.1 — propagă evenimentele detectate pe portal.just.ro în workflow-ul
 * dosarului. Strategie CONSERVATOARE (decizie produs + avocat-senior):
 *
 *  - AUTO (date structurate / efect protectiv):
 *      HEARING_SCHEDULED → `fixeaza_termen` + termen JUDECATA.
 *      APPEAL_FILED      → `formuleaza_cerere_anulare` (protectiv: previne
 *                          marcarea prematură ca DEFINITIVA).
 *  - PROPUNERE (depinde de interpretarea textului liber al soluției):
 *      HEARING_COMPLETED → loghează propunerea (`emite_ordonanta`/`respinge`)
 *                          pentru confirmarea manuală a avocatului (UI Pas 7.2).
 *                          NU se aplică automat: o `emite_ordonanta` greșită
 *                          declanșează termenul critic de 10 zile (CPC art. 1024)
 *                          pe date eronate, fără repunere în termen.
 *
 * Orice tranziție AUTO e gardată cu {@see CaseWorkflowService::can()}; dacă
 * starea curentă nu o permite, se loghează skip (fără excepție). Excepțiile
 * per-event sunt prinse și log-uite, NU propagate (un event eșuat nu blochează
 * restul) — pattern din {@see \App\EventSubscriber\DeadlineCreationSubscriber}.
 */
final class MonitoringEventApplier
{
    public function __construct(
        private readonly CaseWorkflowService $workflowService,
        private readonly DeadlineService $deadlineService,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly WorkingDayResolver $workingDayResolver,
        private readonly AuditLogService $auditLogService,
        private readonly RulingProposalResolver $proposalResolver,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param CourtPortalEvent[] $events
     */
    public function applyEvents(LegalCase $case, array $events): void
    {
        foreach ($events as $event) {
            try {
                $this->applyForEvent($case, $event);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to apply portal event to workflow', [
                    'caseId' => $case->getId(),
                    'eventType' => $event->getEventType()->value,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function applyForEvent(LegalCase $case, CourtPortalEvent $event): void
    {
        match ($event->getEventType()) {
            PortalEventType::HEARING_SCHEDULED => $this->onHearingScheduled($case, $event),
            PortalEventType::APPEAL_FILED => $this->onAppealFiled($case, $event),
            PortalEventType::HEARING_COMPLETED, PortalEventType::RULING_ISSUED => $this->onRulingProposal($case, $event),
            PortalEventType::CASE_INFO_UPDATE => $this->logger->info('Portal case info update (informational only)', [
                'caseId' => $case->getId(),
            ]),
        };
    }

    /**
     * AUTO: termen de judecată detectat. Aplică `fixeaza_termen` dacă starea
     * permite (DOSAR_INREGISTRAT) și creează termenul JUDECATA cu data din
     * portal (câmp structurat, nu text liber). Termenul se creează chiar dacă
     * tranziția nu mai e disponibilă (ex. al doilea termen dintr-un dosar deja
     * în TERMEN_FIXAT).
     */
    private function onHearingScheduled(LegalCase $case, CourtPortalEvent $event): void
    {
        $eventDate = $event->getEventDate();
        if ($eventDate === null) {
            $this->logger->warning('HEARING_SCHEDULED without date, skipping', ['caseId' => $case->getId()]);

            return;
        }

        $hearingDate = \DateTimeImmutable::createFromInterface($eventDate);

        $this->applyTransitionIfPossible($case, CaseTransition::FIXEAZA_TERMEN, $event);

        $deadlineDate = $this->workingDayResolver->nextWorkingDay($hearingDate);
        if ($this->deadlineRepository->findOneByCaseTypeAndDate($case, DeadlineType::JUDECATA, $deadlineDate) !== null) {
            return;
        }

        $this->deadlineService->createHearingDeadline($case, $hearingDate, $event->getDescription());
    }

    /**
     * AUTO (protectiv): cale de atac detectată din câmp structurat. Aplică
     * `formuleaza_cerere_anulare` dacă starea permite (ORDONANTA_EMISA) pentru a
     * preveni marcarea prematură a dosarului ca DEFINITIVA.
     */
    private function onAppealFiled(LegalCase $case, CourtPortalEvent $event): void
    {
        $this->applyTransitionIfPossible($case, CaseTransition::FORMULEAZA_CERERE_ANULARE, $event);
    }

    /**
     * PROPUNERE: ședință cu soluție / hotărâre. NU aplică nicio tranziție
     * automat. Loghează o propunere (advisory) cu tranziția sugerată din
     * heuristica pe text, pe care avocatul o confirmă manual din UI.
     */
    private function onRulingProposal(LegalCase $case, CourtPortalEvent $event): void
    {
        $suggested = $this->proposalResolver->suggestedTransition($case, $event);

        $this->auditLogService->log(
            action: 'portal_transition_proposed',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            newData: [
                'caseNumber' => $case->getCaseNumber(),
                'courtCaseNumber' => $case->getCourtCaseNumber(),
                'eventType' => $event->getEventType()->value,
                'currentStatus' => $case->getStatus()->value,
                'suggestedTransition' => $suggested,
                'solutie' => $event->getSolutie(),
                'solutieSumar' => $event->getSolutieSumar(),
            ],
            category: AuditLogService::CATEGORY_PORTAL_MONITORING,
        );
        $this->em->flush();

        $this->logger->info('Portal ruling proposal logged for manual review', [
            'caseId' => $case->getId(),
            'suggestedTransition' => $suggested,
        ]);
    }

    private function applyTransitionIfPossible(LegalCase $case, CaseTransition $transition, CourtPortalEvent $event): void
    {
        if (!$this->workflowService->can($case, $transition->value)) {
            $this->logger->info('Portal transition skipped, not available from current state', [
                'caseId' => $case->getId(),
                'transition' => $transition->value,
                'currentStatus' => $case->getStatus()->value,
            ]);

            return;
        }

        $fromStatus = $case->getStatus()->value;
        $this->workflowService->apply($case, $transition->value);

        $this->auditLogService->log(
            action: 'portal_transition_applied',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            oldData: ['status' => $fromStatus],
            newData: [
                'caseNumber' => $case->getCaseNumber(),
                'courtCaseNumber' => $case->getCourtCaseNumber(),
                'transition' => $transition->value,
                'status' => $case->getStatus()->value,
                'eventType' => $event->getEventType()->value,
            ],
            category: AuditLogService::CATEGORY_PORTAL_MONITORING,
        );
        $this->em->flush();
    }

}
