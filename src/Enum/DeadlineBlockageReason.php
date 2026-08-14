<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why the next act of the case is stuck: most often because the fact a fatal term runs
 * from has no date, so the term was never created or stays estimated, and once because
 * a piece the procedure imposes is missing and the filing waits on it.
 *
 * Nothing here is stored. The reason is derived at read time from fields and documents
 * that already exist on {@see \App\Entity\LegalCase}, by
 * {@see \App\Service\Deadline\DeadlineBlockageFinder}.
 *
 * The reasons stay mutually exclusive, so one case contributes at most one blockage and
 * counting blockages is the same as counting blocked cases. Most of them are separated
 * by case status; the two on the summons share their statuses and are separated instead
 * by the communication date, one applying while it is missing and the other only once it
 * is recorded.
 */
enum DeadlineBlockageReason: string
{
    /** Summons generated, receipt by the debtor never recorded (CPC art. 1015 para. 1). */
    case SUMMONS_COMMUNICATION_MISSING = 'SUMMONS_COMMUNICATION_MISSING';

    /**
     * Receipt date recorded, the document proving it never attached. Unlike its siblings
     * this blocks no calculation: the 15-day term of CPC art. 1015 para. 1 is already
     * running from the date. What it blocks is the filing: CPC art. 1016 para. 2 requires
     * the proof of the communication made under art. 1015 para. 1 to be attached to the
     * petition, under sanction of the petition being dismissed as inadmissible, so the
     * court is shown the bailiff record or the postal acknowledgement rather than the
     * lawyer's word for the date.
     *
     * Listed here rather than left to the day of filing so the gap surfaces while there
     * is still time to ask the bailiff for the record, instead of at the moment the
     * package was supposed to leave.
     */
    case SUMMONS_PROOF_MISSING = 'SUMMONS_PROOF_MISSING';

    /** Order issued, communication of the ruling never recorded (CPC art. 1024 para. 1). */
    case RULING_COMMUNICATION_MISSING = 'RULING_COMMUNICATION_MISSING';

    /** Claim filed unstamped, the court notice to stamp never recorded (OUG 80/2013 art. 33 para. 2). */
    case STAMP_DUTY_NOTICE_MISSING = 'STAMP_DUTY_NOTICE_MISSING';

    /**
     * Case final, with neither the communication date nor the ruling date recorded, so
     * the three years of CPC art. 705 para. 1 have no date to run from and the term is
     * deliberately not created: any anchor the application could invent would be later
     * than the real one.
     */
    case EXECUTION_ANCHOR_MISSING = 'EXECUTION_ANCHOR_MISSING';

    /**
     * Case that went through an annulment request and is now final, without the date
     * the ruling given on that request was communicated. That ruling, not the lapse of
     * the ten days, is what made the order final (CPC art. 1024 para. 8), so the three
     * years of CPC art. 705 para. 1 run from its communication (para. 2). Anchoring on
     * the first ruling instead would produce a term expiring earlier than the real one.
     */
    case ANNULMENT_RULING_COMMUNICATION_MISSING = 'ANNULMENT_RULING_COMMUNICATION_MISSING';

    /**
     * Case in enforcement, with the date the request was filed with the bailiff recorded
     * and the registration number the bailiff assigned to it still missing. The number is
     * what closes the enforcement-limitation term, because the interruption of CPC art.
     * 708 para. 1 pt. 2 has to rest on a filing confirmed from outside the platform, so
     * until it arrives the three years stay under watch even though the act was done.
     *
     * Disjoint from the others by status: this is the only one on EXECUTARE.
     */
    case ENFORCEMENT_REGISTRATION_NUMBER_MISSING = 'ENFORCEMENT_REGISTRATION_NUMBER_MISSING';

    public function label(): string
    {
        return $this->key('label');
    }

    /**
     * The state the case is in, in two or three words, printed where a term would
     * print its date. These rows have no date to print, and inventing one is exactly
     * what the blockage exists to prevent, so the row states the situation instead:
     * a communication under way, a notice not yet received, an anchor that is absent,
     * a proof not yet in the file.
     */
    public function stateLabel(): string
    {
        return $this->key('state');
    }

    /**
     * What the case is missing: a term named with its length where a term is what
     * cannot be computed, the act itself where the blockage is a missing piece.
     */
    public function missingDeadlineLabel(): string
    {
        return $this->key('missing');
    }

    /** The article the missing term rests on, quoted as in the generated documents. */
    public function legalBasisLabel(): string
    {
        return $this->key('legal_basis');
    }

    /** What is present on the case and what is not, in the lawyer's own terms. */
    public function causeLabel(): string
    {
        return $this->key('cause');
    }

    /** What the missing date costs, stated without softening. */
    public function consequenceLabel(): string
    {
        return $this->key('consequence');
    }

    public function actionLabel(): string
    {
        return $this->key('action');
    }

    private function key(string $suffix): string
    {
        return 'deadlines.blockage.' . $this->value . '.' . $suffix;
    }
}
