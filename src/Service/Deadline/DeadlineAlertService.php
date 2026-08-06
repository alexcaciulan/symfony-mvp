<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\LegalDeadline;
use App\Enum\DeadlineType;
use App\Event\DeadlineAlertEvent;
use App\Repository\LegalDeadlineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Emits alerts for deadlines approaching expiry or already expired. Run daily by the
 * `app:check-deadlines` command.
 *
 * This service only dispatches {@see DeadlineAlertEvent} and sets per-deadline dedup
 * flags; the actual delivery (email, in-app notification) is left to an event
 * subscriber.
 *
 * Most terms are procedural and short, so the ladder is 7 / 3 / 1 days. The
 * limitation-type terms are the exception: on a three-year or six-month window a
 * first warning at seven days leaves no time to act, so they get two extra tiers on
 * top, spaced to the length of the window they guard.
 *
 * At most one alert per deadline per run: the tightest unset tier wins, on `<=` and
 * not on exact equality, so a missed cron day still fires the alert the next day
 * without sending duplicates. Firing a tier also marks every looser tier as sent,
 * which is what keeps a deadline that first surfaced inside a tight tier from
 * emitting the looser ones afterwards.
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
        $sent7 = $sent3 = $sent1 = $expired = $sentLongRange = 0;

        foreach ($this->deadlineRepository->findIncomplete() as $deadline) {
            $days = $this->daysUntil($nowDate, $deadline);

            if ($days < 0) {
                if (!$deadline->isAlertSentExpired()) {
                    $deadline->setAlertSentExpired(true);
                    $this->dispatch($deadline, $days);
                    ++$expired;
                }

                continue;
            }

            $firedTier = $this->fireTightestTier($deadline, $days);

            match ($firedTier) {
                self::THRESHOLD_URGENT_DAYS => ++$sent1,
                self::THRESHOLD_MEDIUM_DAYS => ++$sent3,
                self::THRESHOLD_EARLY_DAYS => ++$sent7,
                null => null,
                default => ++$sentLongRange,
            };
        }

        $this->em->flush();

        return new DeadlineAlertReport($sent7, $sent3, $sent1, $expired, $sentLongRange);
    }

    /**
     * The whole alert ladder of a deadline, loosest tier first, each with whether it
     * has already gone out. Exposed for the card that lists the reminders of a term:
     * the tiers depend on the type, so a template printing a fixed 7 / 3 / 1 would hide
     * the two tiers a limitation term actually has.
     *
     * @return list<array{days: int, sent: bool}>
     */
    public function alertLadder(LegalDeadline $deadline): array
    {
        $ladder = [];
        foreach (array_reverse($this->tiers($deadline->getType())) as [$tierDays, $isSent]) {
            $ladder[] = ['days' => $tierDays, 'sent' => $isSent($deadline)];
        }

        return $ladder;
    }

    /**
     * How many days before expiry the next alert on this deadline will go out: the
     * loosest tier not yet marked as sent, since firing one marks every looser one.
     * Returns 0 when only the expiry alert is left and null when every alert has gone.
     *
     * Read only, nothing is written. It exists so a screen that promises the lawyer a
     * reminder reads the ladder from the service that owns it: the tiers differ by
     * type, and a template repeating 7 / 3 / 1 would promise a limitation term a
     * warning a week ahead while the job actually sends it a month ahead.
     */
    public function nextAlertDaysBefore(LegalDeadline $deadline): ?int
    {
        foreach (array_reverse($this->tiers($deadline->getType())) as [$tierDays, $isSent]) {
            if (!$isSent($deadline)) {
                return $tierDays;
            }
        }

        return $deadline->isAlertSentExpired() ? null : 0;
    }

    /**
     * Fires the tightest tier the deadline is inside and has not been alerted on yet,
     * marking that tier and every looser one as sent. Returns the day count of the
     * tier that fired, or null when nothing was due.
     */
    private function fireTightestTier(LegalDeadline $deadline, int $days): ?int
    {
        $tiers = $this->tiers($deadline->getType());

        foreach ($tiers as $index => [$tierDays, $isSent]) {
            if ($days > $tierDays || $isSent($deadline)) {
                continue;
            }

            for ($looser = $index, $last = count($tiers); $looser < $last; ++$looser) {
                $tiers[$looser][2]($deadline);
            }

            $this->dispatch($deadline, $days);

            return $tierDays;
        }

        return null;
    }

    /**
     * The alert ladder of a type, tightest tier first. Each entry carries the day
     * count, a predicate telling whether that tier already fired, and the marker that
     * records it, so the loop above stays free of per-tier branching.
     *
     * @return list<array{int, callable(LegalDeadline): bool, callable(LegalDeadline): void}>
     */
    private function tiers(DeadlineType $type): array
    {
        $tiers = [
            [
                self::THRESHOLD_URGENT_DAYS,
                static fn (LegalDeadline $d): bool => $d->isAlertSent1(),
                static function (LegalDeadline $d): void { $d->setAlertSent1(true); },
            ],
            [
                self::THRESHOLD_MEDIUM_DAYS,
                static fn (LegalDeadline $d): bool => $d->isAlertSent3(),
                static function (LegalDeadline $d): void { $d->setAlertSent3(true); },
            ],
            [
                self::THRESHOLD_EARLY_DAYS,
                static fn (LegalDeadline $d): bool => $d->isAlertSent7(),
                static function (LegalDeadline $d): void { $d->setAlertSent7(true); },
            ],
        ];

        // Long-range tiers, outer first. The general limitation is warned at 30 and 14
        // days; the six months that keep the interruption alive (NCC art. 2540) at 60
        // and 30, because that window is shorter than three years and filing a
        // payment-order request takes longer to prepare. Every other type stays on the
        // procedural ladder: the annulment request in particular runs for ten days
        // (CPC art. 1024 para. 1), so a 30-day reminder would fire before it starts.
        $longRange = match ($type) {
            DeadlineType::PRESCRIPTIE => [30, 14],
            DeadlineType::DEPUNERE_CERERE => [60, 30],
            default => null,
        };

        if ($longRange === null) {
            return $tiers;
        }

        [$outer, $inner] = $longRange;
        $tiers[] = [
            $inner,
            static fn (LegalDeadline $d): bool => $d->isAlertSentMidRange(),
            static function (LegalDeadline $d): void { $d->setAlertSentMidRange(true); },
        ];
        $tiers[] = [
            $outer,
            static fn (LegalDeadline $d): bool => $d->isAlertSentLongRange(),
            static function (LegalDeadline $d): void { $d->setAlertSentLongRange(true); },
        ];

        return $tiers;
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
