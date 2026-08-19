<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DeadlineType;
use App\Repository\LegalDeadlineRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One-off repair of the deadlines that were computed under the rules the application
 * used before the free-days counting (CPC art. 181 para. 1 pt. 2) and the prorogation
 * of substantive terms (NCC art. 2554) were adopted. Driven by `app:realign-deadlines`.
 *
 * The whole design rests on one rule: a row is only touched when the stored date is
 * PROVABLY what the previous rule would have produced from the anchor that is still on
 * the case. Any other value means a human moved the date, or a fixture wrote it, and
 * moving it would silently overwrite a decision this code cannot see. `LegalDeadline`
 * carries no provenance column, so reproducing the old arithmetic is the only evidence
 * available, and the rows that fail the check are reported rather than corrected.
 *
 * Two consequences of that rule are worth stating, because they look like gaps:
 *  - TIMBRARE is out of scope entirely. Its term runs from the date the court's notice
 *    was communicated, which is kept only in the audit log and not on the case, so the
 *    anchor cannot be recovered and nothing can be proven;
 *  - a row whose anchor was removed afterwards is skipped, not guessed.
 *
 * Idempotent: after a run the stored date equals what the CURRENT rule produces, which
 * by construction differs from what the previous one produced, so a second run finds
 * nothing left to prove and changes nothing.
 */
final class DeadlineRealignmentService
{
    /**
     * The types the run considers. Deliberately not every type:
     *  - JUDECATA and OTHER hold dates that were entered, never computed;
     *  - TIMBRARE has no recoverable anchor (see the class docblock).
     */
    private const TYPES = [
        DeadlineType::RASPUNS_SOMATIE,
        DeadlineType::CERERE_IN_ANULARE,
        DeadlineType::DEPUNERE_CERERE,
        DeadlineType::PRESCRIPTIE,
        DeadlineType::PRESCRIPTIE_EXECUTARE,
    ];

    public function __construct(
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly DeadlineService $deadlineService,
        private readonly WorkingDayResolver $workingDayResolver,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
    ) {}

    public function realign(bool $dryRun): DeadlineRealignmentReport
    {
        $changes = [];
        $alreadyAligned = 0;

        foreach ($this->deadlineRepository->findOpenByTypesOnLiveCases(self::TYPES) as $deadline) {
            $change = $this->evaluate($deadline);

            if ($change === null) {
                ++$alreadyAligned;
                continue;
            }

            if (!$dryRun) {
                $this->apply($deadline, $change);
            }

            $changes[] = $change;
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return new DeadlineRealignmentReport($changes, $alreadyAligned, $dryRun);
    }

    /**
     * What should happen to this deadline, or null when the stored date is already what
     * the current rule produces and there is nothing to do.
     */
    private function evaluate(LegalDeadline $deadline): ?DeadlineRealignmentChange
    {
        $type = $deadline->getType();
        $case = $deadline->getLegalCase();
        $stored = $deadline->getDeadlineDate()->format('Y-m-d');

        // A summons-answer term on a case whose communication date was never recorded
        // cannot exist any more: the fifteen days run from receipt, and a date derived
        // from the generation of the PDF is not that term. It is removed rather than
        // moved, and only when it is provably the one the old seeding produced.
        if ($type === DeadlineType::RASPUNS_SOMATIE && $case->getPaymentNoticeCommunicationDate() === null) {
            return $this->evaluateOrphanSummonsTerm($deadline, $case, $stored);
        }

        // One PRESCRIPTIE row per due date, so the anchor is not a single field on the
        // case: it is whichever due date this row covers, matched against the stored
        // date rather than assumed.
        if ($type === DeadlineType::PRESCRIPTIE) {
            return $this->evaluatePrescription($deadline, $case, $stored);
        }

        $anchor = $this->anchor($type, $case);
        if ($anchor === null) {
            return $this->change(DeadlineRealignmentChange::ACTION_SKIPPED, $deadline, $stored, reason: DeadlineRealignmentChange::REASON_ANCHOR_MISSING);
        }

        $current = $this->currentRule($type, $anchor);
        $previous = $this->previousRule($type, $anchor);
        if ($current === null || $previous === null) {
            return $this->change(DeadlineRealignmentChange::ACTION_SKIPPED, $deadline, $stored, reason: DeadlineRealignmentChange::REASON_ANCHOR_MISSING);
        }

        $currentDate = $current->format('Y-m-d');
        if ($stored === $currentDate) {
            return null;
        }

        if ($stored !== $previous->format('Y-m-d')) {
            return $this->change(DeadlineRealignmentChange::ACTION_SKIPPED, $deadline, $stored, reason: DeadlineRealignmentChange::REASON_NOT_FROM_PREVIOUS_RULE);
        }

        return $this->change(DeadlineRealignmentChange::ACTION_MOVED, $deadline, $stored, newDate: $currentDate);
    }

    /**
     * A RASPUNS_SOMATIE term on a case without a recorded communication date. It is
     * removed only if it reproduces the seeding that used to run at SOMATIE_TRIMISA,
     * that is the generation date plus the term under the previous or the current
     * counting: both are accepted because the removal decision does not depend on
     * which of the two produced it, only on the date not being a human's.
     */
    private function evaluateOrphanSummonsTerm(LegalDeadline $deadline, LegalCase $case, string $stored): DeadlineRealignmentChange
    {
        $paymentNoticeDate = $case->getPaymentNoticeDate();
        if ($paymentNoticeDate === null) {
            return $this->change(DeadlineRealignmentChange::ACTION_SKIPPED, $deadline, $stored, reason: DeadlineRealignmentChange::REASON_ANCHOR_MISSING);
        }

        $generatedOn = \DateTimeImmutable::createFromInterface($paymentNoticeDate);
        $candidates = [
            $this->currentRule(DeadlineType::RASPUNS_SOMATIE, $generatedOn)?->format('Y-m-d'),
            $this->previousRule(DeadlineType::RASPUNS_SOMATIE, $generatedOn)?->format('Y-m-d'),
        ];

        if (!in_array($stored, $candidates, true)) {
            return $this->change(DeadlineRealignmentChange::ACTION_SKIPPED, $deadline, $stored, reason: DeadlineRealignmentChange::REASON_NOT_FROM_PREVIOUS_RULE);
        }

        return $this->change(DeadlineRealignmentChange::ACTION_REMOVED, $deadline, $stored);
    }

    /**
     * A limitation term of the claim, which exists once per distinct due date (NCC art.
     * 2517). The row does not say which due date it covers, so the stored date is
     * matched against what each due date would produce: equal to a current result means
     * the row is already right, equal to a previous result identifies the due date and
     * gives the date to move to. Anything else is a value this run cannot account for.
     */
    private function evaluatePrescription(LegalDeadline $deadline, LegalCase $case, string $stored): ?DeadlineRealignmentChange
    {
        $dueDates = $this->deadlineService->prescriptionDueDates($case);
        if ($dueDates === []) {
            return $this->change(DeadlineRealignmentChange::ACTION_SKIPPED, $deadline, $stored, reason: DeadlineRealignmentChange::REASON_ANCHOR_MISSING);
        }

        $movesTo = null;
        foreach ($dueDates as $dueDate) {
            $term = $this->deadlineService->limitationTermEnd(DeadlineType::PRESCRIPTIE, $dueDate);
            if ($term === null) {
                continue;
            }

            if ($stored === $term->end->format('Y-m-d')) {
                return null;
            }

            if ($movesTo === null && $stored === $term->rawEnd->format('Y-m-d')) {
                $movesTo = $term->end->format('Y-m-d');
            }
        }

        if ($movesTo === null) {
            return $this->change(DeadlineRealignmentChange::ACTION_SKIPPED, $deadline, $stored, reason: DeadlineRealignmentChange::REASON_NOT_FROM_PREVIOUS_RULE);
        }

        return $this->change(DeadlineRealignmentChange::ACTION_MOVED, $deadline, $stored, newDate: $movesTo);
    }

    /**
     * The date on the case the term of this type runs from, or null when it is not
     * recorded. The enforcement limitation reproduces the choice
     * {@see \App\EventSubscriber\DeadlineCreationSubscriber} makes, so the two cannot
     * disagree about which ruling made the order final.
     */
    private function anchor(DeadlineType $type, LegalCase $case): ?\DateTimeImmutable
    {
        return match ($type) {
            DeadlineType::RASPUNS_SOMATIE, DeadlineType::DEPUNERE_CERERE => $case->getPaymentNoticeCommunicationDate(),
            DeadlineType::CERERE_IN_ANULARE => $case->getRulingCommunicationDate(),
            DeadlineType::PRESCRIPTIE_EXECUTARE => $this->executionAnchor($case),
            default => null,
        };
    }

    private function executionAnchor(LegalCase $case): ?\DateTimeImmutable
    {
        $annulmentDate = $case->getAnnulmentRulingCommunicationDate();
        if ($annulmentDate !== null) {
            return $annulmentDate;
        }

        if ($case->hasPassedThroughAnnulment()) {
            return null;
        }

        $communicationDate = $case->getRulingCommunicationDate();
        if ($communicationDate !== null) {
            return $this->deadlineService->appealTermEnd($communicationDate)->modify('+1 day');
        }

        $finalRulingDate = $case->getFinalRulingDate();

        return $finalRulingDate === null ? null : \DateTimeImmutable::createFromInterface($finalRulingDate);
    }

    /** Maturity under the rules in force today. */
    private function currentRule(DeadlineType $type, \DateTimeImmutable $anchor): ?\DateTimeImmutable
    {
        return match ($type) {
            DeadlineType::DEPUNERE_CERERE => $this->deadlineService->filingInterruptionTermEnd($anchor)->end,
            DeadlineType::PRESCRIPTIE, DeadlineType::PRESCRIPTIE_EXECUTARE => $this->deadlineService->limitationTermEnd($type, $anchor)?->end,
            default => $this->deadlineService->dayBasedTermEnd($type, $anchor)?->end,
        };
    }

    /**
     * Maturity under the rules the application used before. Two differences, and only
     * these two: a day-based term ran for N calendar days instead of N + 1 (the free
     * days of CPC art. 181 para. 1 pt. 2 were not applied), and a substantive term was
     * never prorogated to the next working day.
     *
     * This arithmetic lives here and not in {@see DeadlineService} on purpose: it is
     * not law, it is the shape of the data this run has to recognise, and the domain
     * service must keep exactly one formula per term.
     */
    private function previousRule(DeadlineType $type, \DateTimeImmutable $anchor): ?\DateTimeImmutable
    {
        $days = $this->deadlineService->legalDaysFor($type);
        if ($days !== null) {
            return $this->workingDayResolver->nextWorkingDay($anchor->modify('+' . $days . ' days'));
        }

        return match ($type) {
            DeadlineType::DEPUNERE_CERERE => $this->deadlineService->filingInterruptionTermEnd($anchor)->rawEnd,
            DeadlineType::PRESCRIPTIE, DeadlineType::PRESCRIPTIE_EXECUTARE => $this->deadlineService->limitationTermEnd($type, $anchor)?->rawEnd,
            default => null,
        };
    }

    private function apply(LegalDeadline $deadline, DeadlineRealignmentChange $change): void
    {
        if ($change->action === DeadlineRealignmentChange::ACTION_SKIPPED) {
            return;
        }

        if ($change->action === DeadlineRealignmentChange::ACTION_REMOVED) {
            $this->auditLogService->log(
                action: 'deadline_realignment_removed',
                entityType: LegalDeadline::class,
                entityId: (string) $deadline->getId(),
                oldData: [
                    'type' => $change->type,
                    'deadlineDate' => $change->date,
                ],
                newData: [
                    'caseNumber' => $change->caseNumber,
                    'reason' => 'summons_communication_date_missing',
                ],
                category: AuditLogService::CATEGORY_DEADLINE_DELETED,
            );
            $this->em->remove($deadline);

            return;
        }

        $deadline->setDeadlineDate(new \DateTimeImmutable((string) $change->newDate));
        // The date moved, so a reminder already sent for the old one must not suppress
        // the reminders for the new one.
        $deadline->resetAlertFlags();

        $this->auditLogService->log(
            action: 'deadline_realigned',
            entityType: LegalDeadline::class,
            entityId: (string) $deadline->getId(),
            oldData: ['deadlineDate' => $change->date],
            newData: [
                'deadlineId' => $deadline->getId(),
                'type' => $change->type,
                'caseNumber' => $change->caseNumber,
                'deadlineDate' => $change->newDate,
            ],
            category: AuditLogService::CATEGORY_DEADLINE_EDITED,
        );
    }

    private function change(
        string $action,
        LegalDeadline $deadline,
        string $stored,
        ?string $newDate = null,
        ?string $reason = null,
    ): DeadlineRealignmentChange {
        return new DeadlineRealignmentChange(
            action: $action,
            deadlineId: (int) $deadline->getId(),
            type: $deadline->getType()->value,
            caseNumber: $deadline->getLegalCase()->getCaseNumber(),
            date: $stored,
            newDate: $newDate,
            reason: $reason,
        );
    }
}
