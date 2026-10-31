<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why a case is being nudged even though no deadline is running on it. The blockage
 * zone of the agenda is passive: it lists such cases but sends nothing, while the
 * alerting job only knows how to warn about terms that were computed, which is exactly
 * what these cases lack.
 *
 * Kept apart from {@see DeadlineBlockageReason}, which answers a different question,
 * why a fatal deadline could not be computed, and drives a screen rather than a
 * message. One of these alerts can exist without a missing date, and one missing date
 * does not always deserve a message.
 *
 * The date the order was communicated is deliberately absent: it already has a
 * producer of its own, {@see \App\Event\MissingCommunicationDateEvent}, dispatched by
 * the auto-finalizer, and a second channel for the same fact would double the noise
 * rather than fix it.
 */
enum BlockedCaseAlert: string
{
    /**
     * The court file number is confirmed on the case, so the duty can be paid through
     * the electronic registry in the existing case, while the case still records it as
     * unpaid or deferred. The registry checks the duty at the very first step, issues a
     * notice to stamp with at most ten days and annuls the unstamped petition in
     * chambers, and that notice may be served at the client's seat rather than at the
     * law office. So the payment is not waited for: it is chased from the day the
     * number appears, daily inside the payment window
     * ({@see \App\Service\StampDuty\StampDutyPaymentWindow}) and weekly after it.
     */
    case STAMP_DUTY_DUE = 'STAMP_DUTY_DUE';

    /**
     * Same duty, one step earlier: the portal search found a court case that looks
     * like this one and the lawyer has not confirmed it yet. The court does not wait
     * for that confirmation, so neither does the reminder; it asks for the check and
     * the payment in one message. Dismissing the proposal ends it.
     */
    case STAMP_DUTY_DUE_UNCONFIRMED = 'STAMP_DUTY_DUE_UNCONFIRMED';

    /**
     * The lawyer recorded the date he filed the enforcement request with the bailiff and
     * the registration number that confirms it has not arrived after the grace period.
     * The number is what closes the enforcement-limitation term, so without it the term
     * stays open for three years while its alerts are muted, which is silence rather
     * than safety.
     *
     * The only alert here whose dedup key carries no period: the reminder is sent ONCE
     * per case, ever. Everything else in this enum describes a condition the lawyer has
     * to clear, while this one waits on a third party, and a weekly reminder to chase a
     * bailiff for three years is exactly the noise that teaches lawyers to filter the
     * sender. The case stays visible in the blockage zone of the agenda for as long as
     * the number is missing, which is the passive surface this message replaces.
     */
    case ENFORCEMENT_REGISTRATION_NUMBER_MISSING = 'ENFORCEMENT_REGISTRATION_NUMBER_MISSING';

    public function titleKey(): string
    {
        return 'notification.blocked_case.' . $this->value . '.title';
    }

    public function messageKey(): string
    {
        return 'notification.blocked_case.' . $this->value . '.message';
    }

    public function emailSubjectKey(): string
    {
        return 'email.blocked_case.' . $this->value . '.subject';
    }

    public function emailHeadingKey(): string
    {
        return 'email.blocked_case.' . $this->value . '.heading';
    }

    public function emailBodyKey(): string
    {
        return 'email.blocked_case.' . $this->value . '.body';
    }

    /** The fact the case is waiting on, in one line, for the box of the email. */
    public function emailMissingKey(): string
    {
        return 'email.blocked_case.' . $this->value . '.missing';
    }

    /** The act that unblocks it, in one line, next to the one above. */
    public function emailActionKey(): string
    {
        return 'email.blocked_case.' . $this->value . '.action';
    }
}
