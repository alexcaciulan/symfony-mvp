<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Event\MissingCommunicationDateEvent;
use App\Repository\LegalCaseRepository;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Automatically transitions cases to DEFINITIVA once the annulment-request window
 * has lapsed without challenge (CPC art. 1024 para. 1: 10 days from SERVICE of the
 * payment order).
 *
 * Conservative algorithm (avoids a premature transition):
 *  - the term runs from `rulingCommunicationDate`, not from the ruling date;
 *  - `deadline = nextWorkingDay(rulingCommunicationDate + 10 days)` (CPC art. 181
 *    para. 2 prorogation to the next working day);
 *  - finalize only when `now >= deadline + autoFinalBufferDays` (safety buffer:
 *    a day late beats a premature transition);
 *  - if `rulingCommunicationDate` is null, do not finalize; dispatch
 *    {@see MissingCommunicationDateEvent} so the lawyer fills in the date;
 *  - if the debtor challenged in time, the case is already in IN_ANULARE and falls
 *    outside the ORDONANTA_EMISA set (implicitly skipped by the status filter).
 */
final class CaseAutoFinalizer
{
    /** CPC art. 1024 para. 1: annulment-request window. */
    private const APPEAL_DAYS = 10;

    public function __construct(
        private readonly LegalCaseRepository $caseRepository,
        private readonly WorkingDayResolver $workingDayResolver,
        private readonly CaseWorkflowService $workflowService,
        private readonly AuditLogService $auditLogService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityManagerInterface $em,
        private readonly int $autoFinalBufferDays = 1,
    ) {}

    public function process(\DateTimeImmutable $now): AutoFinalizeReport
    {
        $nowDate = $now->setTime(0, 0);
        $finalized = $missingDate = $notYetDue = 0;

        foreach ($this->caseRepository->findByStatus(CaseStatus::ORDONANTA_EMISA) as $case) {
            $communicationDate = $case->getRulingCommunicationDate();

            if ($communicationDate === null) {
                $this->eventDispatcher->dispatch(new MissingCommunicationDateEvent($case));
                ++$missingDate;
                continue;
            }

            // Recomputed independently from `rulingCommunicationDate` (source of
            // truth), not read from the CERERE_IN_ANULARE LegalDeadline: if the
            // lawyer updated the communication date, we always use the current
            // value. `+10 days` makes the 10th day the last to file (the service
            // day is not counted, CPC art. 181 para. 1 pt. 2); the buffer absorbs
            // the D+10 inclusive/exclusive interpretation ambiguity.
            $deadline = $this->workingDayResolver->nextWorkingDay(
                $communicationDate->modify('+' . self::APPEAL_DAYS . ' days'),
            );
            $finalThreshold = $deadline->modify('+' . $this->autoFinalBufferDays . ' days');

            if ($nowDate < $finalThreshold) {
                ++$notYetDue;
                continue;
            }

            if (!$this->workflowService->can($case, CaseTransition::MARCHEAZA_DEFINITIVA->value)) {
                ++$notYetDue;
                continue;
            }

            $this->finalize($case, $communicationDate, $deadline);
            ++$finalized;
        }

        return new AutoFinalizeReport($finalized, $missingDate, $notYetDue);
    }

    private function finalize(LegalCase $case, \DateTimeImmutable $communicationDate, \DateTimeImmutable $deadline): void
    {
        $this->workflowService->apply($case, CaseTransition::MARCHEAZA_DEFINITIVA->value);

        $this->auditLogService->log(
            action: 'auto_finalized',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            oldData: ['status' => CaseStatus::ORDONANTA_EMISA->value],
            newData: [
                'caseNumber' => $case->getCaseNumber(),
                'courtCaseNumber' => $case->getCourtCaseNumber(),
                'status' => $case->getStatus()->value,
                'rulingCommunicationDate' => $communicationDate->format('Y-m-d'),
                'appealDeadline' => $deadline->format('Y-m-d'),
                'bufferDays' => $this->autoFinalBufferDays,
            ],
            category: AuditLogService::CATEGORY_AUTO_FINALIZED,
        );
        $this->em->flush();
    }
}
