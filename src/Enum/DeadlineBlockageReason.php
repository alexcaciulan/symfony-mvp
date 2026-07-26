<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why a fatal deadline is missing from the agenda: the fact its term runs from has
 * no date on the case, so the term either was never created or stays estimated.
 *
 * Nothing here is stored. The reason is derived at read time from fields that
 * already exist on {@see \App\Entity\LegalCase}, by
 * {@see \App\Service\Deadline\DeadlineBlockageFinder}.
 *
 * The three reasons are mutually exclusive by construction: each one only applies
 * to a disjoint set of case statuses, so one case contributes at most one blockage
 * and counting blockages is the same as counting blocked cases.
 */
enum DeadlineBlockageReason: string
{
    /** Summons generated, receipt by the debtor never recorded (CPC art. 1015 para. 1). */
    case SUMMONS_COMMUNICATION_MISSING = 'SUMMONS_COMMUNICATION_MISSING';

    /** Order issued, communication of the ruling never recorded (CPC art. 1024 para. 1). */
    case RULING_COMMUNICATION_MISSING = 'RULING_COMMUNICATION_MISSING';

    /** Claim filed unstamped, the court notice to stamp never recorded (OUG 80/2013 art. 33 para. 2). */
    case STAMP_DUTY_NOTICE_MISSING = 'STAMP_DUTY_NOTICE_MISSING';

    public function label(): string
    {
        return $this->key('label');
    }

    /** The act that is missing, named as a term with its length. */
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

    /**
     * The existing route that records the missing date. Deliberately the same one the
     * case page posts to, so authorization and audit keep living in a single place.
     */
    public function actionRoute(): string
    {
        return match ($this) {
            self::SUMMONS_COMMUNICATION_MISSING => 'case_deadline_summons_communication_date',
            self::RULING_COMMUNICATION_MISSING => 'case_deadline_ruling_date',
            self::STAMP_DUTY_NOTICE_MISSING => 'case_stamp_duty_court_notice',
        };
    }

    /** Route parameter carrying the case id; the two controllers name it differently. */
    public function actionRouteParameterName(): string
    {
        return match ($this) {
            self::SUMMONS_COMMUNICATION_MISSING, self::RULING_COMMUNICATION_MISSING => 'caseId',
            self::STAMP_DUTY_NOTICE_MISSING => 'id',
        };
    }

    private function key(string $suffix): string
    {
        return 'deadlines.blockage.' . $this->value . '.' . $suffix;
    }
}
