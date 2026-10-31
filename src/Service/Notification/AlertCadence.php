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

    /**
     * One key per calendar day, so the daily job delivers every morning. Reserved for
     * the few days in which the condition is both urgent and fully in the lawyer's
     * hands, such as the payment window of the stamp duty after the file number
     * appears: there the repetition is the point, and a week of silence would be the
     * noise.
     */
    public static function daily(string $reason, int $caseId, \DateTimeImmutable $on): string
    {
        return sprintf('%s:%d:%s', $reason, $caseId, $on->format('Y-m-d'));
    }

    /**
     * Key without a period, so the condition produces exactly one message per case, ever.
     * The dedup lookup in {@see NotificationDispatcher} refuses every later delivery on
     * the same key, on both channels, and no later run can build a different one.
     *
     * For the conditions that wait on a third party rather than on the lawyer: repeating
     * a reminder he cannot clear by himself is what turns the sender into noise. The
     * standing surface for those is the blockage zone of the agenda, which is passive.
     */
    public static function once(string $reason, int $caseId): string
    {
        return sprintf('%s:%d', $reason, $caseId);
    }
}
