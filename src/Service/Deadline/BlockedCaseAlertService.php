<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalCase;
use App\Enum\BlockedCaseAlert;
use App\Event\BlockedCaseAlertEvent;
use App\Repository\LegalCaseRepository;
use App\Service\Notification\AlertCadence;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Alerts for cases that need an act from the lawyer while no deadline is counting down
 * on them. Run daily by `app:check-deadlines`, alongside the deadline alerts.
 *
 * It exists because the two mechanisms the application had left a hole between them:
 * the alerting job only warns about terms that were computed, and the blockage zone of
 * the agenda lists the cases where no term could be computed but sends nothing. A case
 * waiting on a date the lawyer has to type therefore reached him only if he opened the
 * agenda.
 *
 * Most alerts carry a weekly dedup key, so a condition that stays true for a month
 * produces four messages and not thirty. The key gates both channels
 * ({@see \App\Service\Notification\NotificationDispatcher}), which is the whole point:
 * an unthrottled daily email is what makes a lawyer filter the sender.
 *
 * The exception is the missing bailiff registration number, which is sent once and never
 * again: it waits on a third party rather than on the lawyer, and a weekly reminder to
 * chase a bailiff would run for the three years of the term it guards.
 */
final class BlockedCaseAlertService
{
    public function __construct(
        private readonly LegalCaseRepository $caseRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly int $enforcementRegistrationGraceDays = 10,
    ) {}

    public function process(\DateTimeImmutable $now): BlockedCaseAlertReport
    {
        $stampDutyDue = $this->dispatchAll(
            $this->caseRepository->findStampDutyDueAfterCaseNumber(),
            BlockedCaseAlert::STAMP_DUTY_DUE,
            static fn (int $caseId): string => AlertCadence::weekly('blocked_case:' . BlockedCaseAlert::STAMP_DUTY_DUE->value, $caseId, $now),
        );

        $regularization = $this->dispatchAll(
            $this->caseRepository->findAwaitingRegularizationNoticeDate(),
            BlockedCaseAlert::REGULARIZATION_NOTICE_DATE_MISSING,
            static fn (int $caseId): string => AlertCadence::weekly('blocked_case:' . BlockedCaseAlert::REGULARIZATION_NOTICE_DATE_MISSING->value, $caseId, $now),
        );

        // The grace runs in calendar days from the date the lawyer declared he filed the
        // request: the bailiff registers it on receipt, so what the grace covers is the
        // round trip of the confirmation, not a procedural term.
        $registrationNumber = $this->dispatchAll(
            $this->caseRepository->findEnforcementRegistrationNumberOverdue(
                $now->setTime(0, 0)->modify('-' . $this->enforcementRegistrationGraceDays . ' days'),
            ),
            BlockedCaseAlert::ENFORCEMENT_REGISTRATION_NUMBER_MISSING,
            static fn (int $caseId): string => AlertCadence::once('blocked_case:' . BlockedCaseAlert::ENFORCEMENT_REGISTRATION_NUMBER_MISSING->value, $caseId),
        );

        return new BlockedCaseAlertReport($stampDutyDue, $regularization, $registrationNumber);
    }

    /**
     * @param LegalCase[]            $cases
     * @param callable(int): string  $dedupKey cadence of this reason, built per case
     *
     * @return int number of events dispatched (delivery, and its throttling, is decided downstream)
     */
    private function dispatchAll(array $cases, BlockedCaseAlert $reason, callable $dedupKey): int
    {
        $dispatched = 0;

        foreach ($cases as $case) {
            $caseId = $case->getId();
            if ($caseId === null) {
                continue;
            }

            $this->eventDispatcher->dispatch(new BlockedCaseAlertEvent($case, $reason, $dedupKey($caseId)));
            ++$dispatched;
        }

        return $dispatched;
    }
}
