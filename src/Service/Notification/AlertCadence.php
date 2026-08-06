<?php

declare(strict_types=1);

namespace App\Service\Notification;

/**
 * Dedup keys for alerts about STANDING conditions: facts that stay true until somebody
 * acts, checked by a job that runs every day. Without a key encoding the period, such a
 * check delivers the same message every morning, which is how an alert becomes
 * background noise and stops being read.
 *
 * The period is the ISO week. A day would not throttle anything, and a month would let
 * a case that needs an act sit unmentioned for four weeks. The key is built from the
 * ISO year and week rather than from the date, so every day of the same week produces
 * the same key and the second delivery inside it is refused by the dedup lookup in
 * {@see NotificationDispatcher}.
 */
final class AlertCadence
{
    /** Stable weekly key for one reason on one case. */
    public static function weekly(string $reason, int $caseId, \DateTimeImmutable $on): string
    {
        return sprintf('%s:%d:%s', $reason, $caseId, $on->format('o-\WW'));
    }
}
