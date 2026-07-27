<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DeadlineBlockageReason;
use App\Repository\LegalCaseRepository;

/**
 * Collects the cases where a fatal deadline is missing or estimated because the
 * fact it runs from carries no date. Everything here is derived from fields that
 * already exist on {@see LegalCase}; no column and no migration back this list.
 *
 * The reasons apply to disjoint sets of case statuses, so a case appears at most
 * once and the number of blockages equals the number of blocked cases, which is what
 * the risk bar counts.
 */
final class DeadlineBlockageFinder
{
    public function __construct(
        private readonly LegalCaseRepository $cases,
    ) {}

    /**
     * Ordered by how close the missing date is to costing something: the summons
     * receipt gates filing, the ruling communication gates a forfeiture term, the
     * stamping notice gates annulment of a claim already on the court's desk, and the
     * enforcement anchor gates a term that is three years out but silently absent.
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
                $this->cases->findAwaitingRulingCommunicationDate($user),
                DeadlineBlockageReason::RULING_COMMUNICATION_MISSING,
            ),
            ...$this->wrap(
                $this->cases->findAwaitingStampDutyCourtNotice($user),
                DeadlineBlockageReason::STAMP_DUTY_NOTICE_MISSING,
            ),
            ...$this->wrap(
                $this->cases->findAwaitingExecutionPrescriptionAnchor($user),
                DeadlineBlockageReason::EXECUTION_ANCHOR_MISSING,
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
