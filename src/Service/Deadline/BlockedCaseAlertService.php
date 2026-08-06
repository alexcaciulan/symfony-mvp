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
 * Every alert carries a weekly dedup key, so a condition that stays true for a month
 * produces four messages and not thirty. The key gates both channels
 * ({@see \App\Service\Notification\NotificationDispatcher}), which is the whole point:
 * an unthrottled daily email is what makes a lawyer filter the sender.
 */
final class BlockedCaseAlertService
{
    public function __construct(
        private readonly LegalCaseRepository $caseRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function process(\DateTimeImmutable $now): BlockedCaseAlertReport
    {
        $stampDutyDue = $this->dispatchAll(
            $this->caseRepository->findStampDutyDueAfterCaseNumber(),
            BlockedCaseAlert::STAMP_DUTY_DUE,
            $now,
        );

        $regularization = $this->dispatchAll(
            $this->caseRepository->findAwaitingRegularizationNoticeDate(),
            BlockedCaseAlert::REGULARIZATION_NOTICE_DATE_MISSING,
            $now,
        );

        return new BlockedCaseAlertReport($stampDutyDue, $regularization);
    }

    /**
     * @param LegalCase[] $cases
     *
     * @return int number of events dispatched (delivery, and its throttling, is decided downstream)
     */
    private function dispatchAll(array $cases, BlockedCaseAlert $reason, \DateTimeImmutable $now): int
    {
        $dispatched = 0;

        foreach ($cases as $case) {
            $caseId = $case->getId();
            if ($caseId === null) {
                continue;
            }

            $this->eventDispatcher->dispatch(new BlockedCaseAlertEvent(
                $case,
                $reason,
                AlertCadence::weekly('blocked_case:' . $reason->value, $caseId, $now),
            ));
            ++$dispatched;
        }

        return $dispatched;
    }
}
