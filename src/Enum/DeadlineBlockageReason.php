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
 * The reasons are mutually exclusive by construction: each one only applies to a
 * disjoint set of case statuses, so one case contributes at most one blockage and
 * counting blockages is the same as counting blocked cases.
 */
enum DeadlineBlockageReason: string
{
    /** Summons generated, receipt by the debtor never recorded (CPC art. 1015 para. 1). */
    case SUMMONS_COMMUNICATION_MISSING = 'SUMMONS_COMMUNICATION_MISSING';

    /** Order issued, communication of the ruling never recorded (CPC art. 1024 para. 1). */
    case RULING_COMMUNICATION_MISSING = 'RULING_COMMUNICATION_MISSING';

    /** Claim filed unstamped, the court notice to stamp never recorded (OUG 80/2013 art. 33 para. 2). */
    case STAMP_DUTY_NOTICE_MISSING = 'STAMP_DUTY_NOTICE_MISSING';

    /**
     * Case final or already in enforcement, with neither the communication date nor
     * the ruling date recorded, so the three years of CPC art. 705 para. 1 have no
     * date to run from and the term is deliberately not created: any anchor the
     * application could invent would be later than the real one.
     */
    case EXECUTION_ANCHOR_MISSING = 'EXECUTION_ANCHOR_MISSING';

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

    private function key(string $suffix): string
    {
        return 'deadlines.blockage.' . $this->value . '.' . $suffix;
    }
}
