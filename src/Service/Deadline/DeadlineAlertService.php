<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalDeadline;
use App\Event\DeadlineAlertEvent;
use App\Repository\LegalDeadlineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Emits alerts for procedural deadlines approaching expiry (7 / 3 / 1 days) or
 * already expired. Run daily by the `app:check-deadlines` command.
 *
 * This service only dispatches {@see DeadlineAlertEvent} and sets a per-deadline
 * dedup flag; the actual delivery (email, in-app notification, Mercure push) is
 * left to an event subscriber.
 *
 * At most one alert per deadline per run: the tightest unset threshold wins
 * (`<= 7 / <= 3 / <= 1 / < 0`), not exact equality, so a missed cron day still
 * fires the alert the next day without sending duplicates.
 */
final class DeadlineAlertService
{
    private const THRESHOLD_EARLY_DAYS = 7;
    private const THRESHOLD_MEDIUM_DAYS = 3;
    private const THRESHOLD_URGENT_DAYS = 1;

    public function __construct(
        private readonly LegalDeadlineRepository $deadlineRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityManagerInterface $em,
    ) {}

    public function processAlerts(\DateTimeImmutable $now): DeadlineAlertReport
    {
        $nowDate = $now->setTime(0, 0);
        $sent7 = $sent3 = $sent1 = $expired = 0;

        foreach ($this->deadlineRepository->findIncomplete() as $deadline) {
            $days = $this->daysUntil($nowDate, $deadline);

            if ($days < 0 && !$deadline->isAlertSentExpired()) {
                $deadline->setAlertSentExpired(true);
                $this->dispatch($deadline, $days);
                ++$expired;
            } elseif ($days >= 0 && $days <= self::THRESHOLD_URGENT_DAYS && !$deadline->isAlertSent1()) {
                $deadline->setAlertSent7(true)->setAlertSent3(true)->setAlertSent1(true);
                $this->dispatch($deadline, $days);
                ++$sent1;
            } elseif ($days <= self::THRESHOLD_MEDIUM_DAYS && !$deadline->isAlertSent3()) {
                $deadline->setAlertSent7(true)->setAlertSent3(true);
                $this->dispatch($deadline, $days);
                ++$sent3;
            } elseif ($days <= self::THRESHOLD_EARLY_DAYS && !$deadline->isAlertSent7()) {
                $deadline->setAlertSent7(true);
                $this->dispatch($deadline, $days);
                ++$sent7;
            }
        }

        $this->em->flush();

        return new DeadlineAlertReport($sent7, $sent3, $sent1, $expired);
    }

    /** Signed days remaining (negative = expired), at calendar-day granularity. */
    private function daysUntil(\DateTimeImmutable $nowDate, LegalDeadline $deadline): int
    {
        $deadlineDate = $deadline->getDeadlineDate()->setTime(0, 0);

        return (int) $nowDate->diff($deadlineDate)->format('%r%a');
    }

    private function dispatch(LegalDeadline $deadline, int $daysRemaining): void
    {
        $this->eventDispatcher->dispatch(new DeadlineAlertEvent($deadline, $daysRemaining));
    }
}
