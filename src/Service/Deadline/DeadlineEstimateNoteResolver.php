<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DeadlineType;
use Psr\Clock\ClockInterface;

/**
 * Builds the marker printed under an agenda row whose date needs qualifying. One
 * wording cannot serve every type, because the reasons are of two opposite kinds and
 * the row has room for a single short line.
 *
 * On every type but PRESCRIPTIE the fact the term runs from has no confirmed date on
 * the case, so the date shown is a working assumption and the lawyer has to record
 * the real one.
 *
 * On PRESCRIPTIE the generating fact, the due date, IS confirmed. What qualifies the
 * date is that another date has been confirmed since, the communication of the
 * summons, which interrupts the limitation period (CPC art. 1015 para. 2 referring to
 * NCC art. 2540). The interruption is not folded into the stored date, which is still
 * the due date plus three years, so the row states until when it holds instead of
 * claiming the date is uncertain. Three states, because the interruption produced by the
 * summons is conditional until the request reaches the court and unconditional after
 * (NCC art. 2537 pt. 2), and the difference is the whole point of the six-month term.
 */
final class DeadlineEstimateNoteResolver
{
    private const UNCONFIRMED_FACT = 'deadlines.row.estimate.unconfirmed_fact';
    private const INTERRUPTED_BY_SUMMONS = 'deadlines.row.estimate.prescription_interrupted';
    private const INTERRUPTED_BY_FILING = 'deadlines.row.estimate.prescription_interrupted_by_filing';
    private const INTERRUPTION_LAPSED = 'deadlines.row.estimate.prescription_interruption_lapsed';
    private const RAN_OUT_BEFORE_SUMMONS = 'deadlines.row.estimate.prescription_ran_out_before_summons';
    private const RAN_OUT_BEFORE_LATE_FILING = 'deadlines.row.estimate.prescription_ran_out_before_late_filing';

    public function __construct(
        private readonly DeadlineService $deadlineService,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * `$lastCovered` is the last due date of a merged row: a statement about the row has
     * to hold for every term it stands for, so a fact that ended a term is only stated
     * once it also ended the last one.
     */
    public function resolveFor(LegalDeadline $deadline, ?\DateTimeImmutable $lastCovered = null): DeadlineEstimateNote
    {
        $legalCase = $deadline->getLegalCase();
        $communicationDate = $legalCase->getPaymentNoticeCommunicationDate();

        if ($deadline->getType() === DeadlineType::PRESCRIPTIE && $communicationDate !== null) {
            $last = ($lastCovered ?? $deadline->getDeadlineDate())->format('Y-m-d');

            // A period that had already run out when the summons arrived cannot be
            // interrupted by it, so the row must not claim a protection it never had.
            if ($last < $communicationDate->format('Y-m-d')) {
                return $this->note(self::RAN_OUT_BEFORE_SUMMONS);
            }

            // Past the six months the summons no longer holds, and a request filed after
            // the term ran out cannot interrupt it either (NCC art. 2537 pt. 2).
            $filedAt = $legalCase->getFiledAt()?->format('Y-m-d');
            $holdsUntil = $this->deadlineService->filingInterruptionTermEnd($communicationDate)->end->format('Y-m-d');
            if ($filedAt !== null && $filedAt > $holdsUntil && $last < $filedAt) {
                return $this->note(self::RAN_OUT_BEFORE_LATE_FILING);
            }
        }

        return $this->resolve($deadline->getType(), $legalCase);
    }

    public function resolve(DeadlineType $type, LegalCase $legalCase): DeadlineEstimateNote
    {
        if ($type !== DeadlineType::PRESCRIPTIE) {
            return $this->note(self::UNCONFIRMED_FACT);
        }

        $communicationDate = $legalCase->getPaymentNoticeCommunicationDate();
        if ($communicationDate === null) {
            // Unreachable on screen: without that date the term is CERT and nothing is
            // printed. Kept so the resolver answers for any deadline it is handed.
            return $this->note(self::UNCONFIRMED_FACT);
        }

        if (!$this->deadlineService->isBeforeFiling($legalCase)) {
            return $this->note(self::INTERRUPTED_BY_FILING);
        }

        // The same arithmetic the six-month term is stored with, so the date the row
        // states and the date the agenda watches can never drift apart.
        $holdsUntil = $this->deadlineService->filingInterruptionTermEnd($communicationDate)->end;

        // Past that date with nothing filed, the interruption is deemed never to have
        // happened (NCC art. 2540), so the row must stop reading as a protection.
        if ($holdsUntil->format('Y-m-d') < $this->clock->now()->format('Y-m-d')) {
            return $this->note(self::INTERRUPTION_LAPSED);
        }

        return $this->note(self::INTERRUPTED_BY_SUMMONS, ['%date%' => $holdsUntil->format('d.m.Y')]);
    }

    /** @param array<string, string> $parameters */
    private function note(string $base, array $parameters = []): DeadlineEstimateNote
    {
        return new DeadlineEstimateNote($base . '.mark', $base . '.note', $parameters);
    }
}
