<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DeadlineBlockageReason;
use App\Repository\LegalCaseRepository;

/**
 * Collects the cases whose next act is stuck: a fatal deadline missing or estimated
 * because the fact it runs from carries no date, or the filing held back for want of a
 * piece the procedure imposes. Everything here is derived from fields and documents
 * that already exist on {@see LegalCase}; no column and no migration back this list.
 *
 * The reasons remain mutually exclusive, so a case appears at most once and the number
 * of blockages equals the number of blocked cases, which is what the risk bar counts.
 * Most of them are separated by case status; the two on the summons share their
 * statuses and are separated by the communication date instead.
 */
final class DeadlineBlockageFinder
{
    public function __construct(
        private readonly LegalCaseRepository $cases,
    ) {}

    /**
     * Ordered by how close the gap is to costing something: the summons receipt gates
     * filing, its proof gates the same filing one step later, the ruling communication
     * gates a forfeiture term, the stamping notice gates annulment of a claim already on
     * the court's desk, the two enforcement anchors gate a term that is three years out
     * but silently absent, and the missing registration number leaves that same term
     * open with its alerts muted.
     *
     * @return list<DeadlineBlockage>
     */
    public function find(User $user): array
    {
        return [
            ...$this->wrap(
                $this->cases->findAwaitingSummonsCommunicationDate($user),
                DeadlineBlockageReason::SUMMONS_COMMUNICATION_MISSING,
            ),
            ...$this->wrap(
                $this->cases->findAwaitingSummonsCommunicationProof($user),
                DeadlineBlockageReason::SUMMONS_PROOF_MISSING,
            ),
            ...$this->wrap(
                $this->cases->findAwaitingRulingCommunicationDate($user),
                DeadlineBlockageReason::RULING_COMMUNICATION_MISSING,
            ),
            ...$this->wrap(
                $this->cases->findAwaitingStampDutyCourtNotice($user),
                DeadlineBlockageReason::STAMP_DUTY_NOTICE_MISSING,
            ),
            ...$this->wrap(
                $this->cases->findAwaitingAnnulmentRulingCommunicationDate($user),
                DeadlineBlockageReason::ANNULMENT_RULING_COMMUNICATION_MISSING,
            ),
            ...$this->wrap(
                $this->cases->findAwaitingExecutionPrescriptionAnchor($user),
                DeadlineBlockageReason::EXECUTION_ANCHOR_MISSING,
            ),
            ...$this->wrap(
                $this->cases->findAwaitingEnforcementRegistrationNumber($user),
                DeadlineBlockageReason::ENFORCEMENT_REGISTRATION_NUMBER_MISSING,
            ),
        ];
    }

    /**
     * @param LegalCase[] $cases
     *
     * @return list<DeadlineBlockage>
     */
    private function wrap(array $cases, DeadlineBlockageReason $reason): array
    {
        return array_map(
            static fn (LegalCase $case): DeadlineBlockage => new DeadlineBlockage($case, $reason),
            array_values($cases),
        );
    }
}
