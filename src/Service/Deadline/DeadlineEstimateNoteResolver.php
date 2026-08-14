<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Enum\DeadlineType;

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
 * claiming the date is uncertain. Two states, because the interruption produced by the
 * summons is conditional until the request reaches the court and unconditional after
 * (NCC art. 2537 pt. 2), and the difference is the whole point of the six-month term.
 */
final class DeadlineEstimateNoteResolver
{
    private const UNCONFIRMED_FACT = 'deadlines.row.estimate.unconfirmed_fact';
    private const INTERRUPTED_BY_SUMMONS = 'deadlines.row.estimate.prescription_interrupted';
    private const INTERRUPTED_BY_FILING = 'deadlines.row.estimate.prescription_interrupted_by_filing';

    public function __construct(private readonly DeadlineService $deadlineService) {}

    public function resolveFor(LegalDeadline $deadline): DeadlineEstimateNote
    {
        return $this->resolve($deadline->getType(), $deadline->getLegalCase());
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

        return $this->note(self::INTERRUPTED_BY_SUMMONS, ['%date%' => $holdsUntil->format('d.m.Y')]);
    }

    /** @param array<string, string> $parameters */
    private function note(string $base, array $parameters = []): DeadlineEstimateNote
    {
        return new DeadlineEstimateNote($base . '.mark', $base . '.note', $parameters);
    }
}
