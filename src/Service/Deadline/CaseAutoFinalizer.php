<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Event\MissingCommunicationDateEvent;
use App\Repository\LegalCaseRepository;
use App\Repository\LegalDeadlineRepository;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Notification\AlertCadence;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Automatically transitions cases to DEFINITIVA once the annulment-request window
 * has lapsed without challenge (CPC art. 1024 para. 1: 10 days from SERVICE of the
 * payment order).
 *
 * Conservative algorithm (avoids a premature transition):
 *  - the term runs from `rulingCommunicationDate`, not from the ruling date;
 *  - the maturity date comes from {@see DeadlineService::appealTermEnd()}, so it is
 *    the same date the CERERE_IN_ANULARE deadline shows the lawyer: free days (CPC
 *    art. 181 para. 1 pt. 2, hence communication + 11 calendar days) plus the
 *    prorogation to the next working day (para. 2);
 *  - finalize only when `now >= addWorkingDays(deadline, autoFinalBufferDays)`
 *    (safety buffer in WORKING days, so holiday clusters like Easter/Christmas are
 *    absorbed automatically; a few days late beats a premature transition);
 *  - if `rulingCommunicationDate` is null, do not finalize; dispatch
 *    {@see MissingCommunicationDateEvent} so the lawyer fills in the date;
 *  - if the debtor challenged in time, the case is already in IN_ANULARE and falls
 *    outside the ORDONANTA_EMISA set (implicitly skipped by the status filter).
 *
 * The same run also closes the CERERE_IN_ANULARE deadlines whose ten days have run.
 * That is a separate pass over the deadlines rather than a step inside the loop
 * above, because the term also has to be closed on cases that already left
 * ORDONANTA_EMISA (finalized on an earlier run, or challenged and now in IN_ANULARE):
 * the term expires on its own date regardless of where the case went afterwards.
 *
 * Expiry is no longer the only way that term ends: a lawyer who has decided not to
 * challenge the order closes it himself
 * ({@see DeadlineService::waiveAnnulmentRequest()}). That changes nothing here. The pass
 * only looks at terms that are still open, and the finalization above is recomputed from
 * `rulingCommunicationDate` rather than read off the deadline record, so a case whose
 * term was closed early still becomes final on the day the ten days plus the buffer say.
 */
final class CaseAutoFinalizer
{
    public function __construct(
        private readonly LegalCaseRepository $caseRepository,
        private readonly WorkingDayResolver $workingDayResolver,
        private readonly DeadlineService $deadlineService,
        private readonly CaseWorkflowService $workflowService,
        private readonly AuditLogService $auditLogService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityManagerInterface $em,
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly int $autoFinalBufferDays = 5,
    ) {}

    public function process(\DateTimeImmutable $now): AutoFinalizeReport
    {
        $nowDate = $now->setTime(0, 0);
        $finalized = $missingDate = $notYetDue = 0;

        foreach ($this->caseRepository->findByStatus(CaseStatus::ORDONANTA_EMISA) as $case) {
            $communicationDate = $case->getRulingCommunicationDate();

            if ($communicationDate === null) {
                // The condition holds every day until the lawyer records the date, and
                // this job runs daily, so the alert carries a weekly key: four messages
                // a month instead of thirty, on a fact that does not change in between.
                $caseId = $case->getId();
                $this->eventDispatcher->dispatch(new MissingCommunicationDateEvent(
                    $case,
                    $caseId === null ? null : AlertCadence::weekly('missing_communication_date', $caseId, $nowDate),
                ));
                ++$missingDate;
                continue;
            }

            // Recomputed from `rulingCommunicationDate` (source of truth) rather
            // than read from the CERERE_IN_ANULARE LegalDeadline, so a communication
            // date the lawyer corrected is always the one applied; the calculation
            // itself is shared with that deadline. The debtor may still file
            // throughout the maturity day, so the buffer is what keeps a same-day
            // finalization from happening.
            $deadline = $this->deadlineService->appealTermEnd($communicationDate);
            $finalThreshold = $this->workingDayResolver->addWorkingDays($deadline, $this->autoFinalBufferDays);

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

        return new AutoFinalizeReport($finalized, $missingDate, $notYetDue, $this->closeLapsedAppealTerms($nowDate));
    }

    /**
     * Closes the annulment-request terms whose ten days have run (CPC art. 1024 para.
     * 1). The maturity date is recomputed from `rulingCommunicationDate` through
     * {@see DeadlineService::appealTermEnd()}, the same source the finalization above
     * uses, so a corrected communication date moves both and no second date can appear.
     *
     * Strictly after the maturity date: the term runs throughout its last day, so a
     * request filed that day is still in time and the term is only spent from the day
     * after. No buffer is added here. The buffer exists to delay a status change that
     * would block a late filing; closing a term that has provably run blocks nothing,
     * and adding days would leave an expired term on screen for no reason.
     *
     * The term is not reopened if the debtor turns out to have filed on the last day:
     * the case then moves to IN_ANULARE, which is the fact the agenda shows, and the
     * ten-day window is spent either way.
     */
    private function closeLapsedAppealTerms(\DateTimeImmutable $nowDate): int
    {
        $closed = 0;

        foreach ($this->deadlineRepository->findOpenAppealDeadlines() as $deadline) {
            $case = $deadline->getLegalCase();
            $communicationDate = $case->getRulingCommunicationDate();
            if ($communicationDate === null) {
                continue;
            }

            if ($nowDate <= $this->deadlineService->appealTermEnd($communicationDate)) {
                continue;
            }

            $this->deadlineService->closeAppealDeadline($case);
            ++$closed;
        }

        return $closed;
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
