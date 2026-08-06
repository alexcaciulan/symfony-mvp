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
     * The court file number has been found, so the claim is registered and the stamp
     * duty is due (OUG 80/2013 art. 33 para. 1: it is paid in advance), while the case
     * still records it as unpaid. Waiting for the court to ask for it is a bet on the
     * court asking.
     */
    case STAMP_DUTY_DUE = 'STAMP_DUTY_DUE';

    /**
     * The duty was deferred pending a regularization notice, and the date of that
     * notice has never been recorded, so the ten days of OUG 80/2013 art. 33 para. 2
     * are not running anywhere in the application.
     *
     * The wording has to stay conditional. The platform cannot know whether the notice
     * arrived: the only evidence it will ever have is the lawyer entering the date. So
     * the message asks for the date IF the notice was received; it never asserts that
     * one is sitting unrecorded.
     */
    case REGULARIZATION_NOTICE_DATE_MISSING = 'REGULARIZATION_NOTICE_DATE_MISSING';

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
