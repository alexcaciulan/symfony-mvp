<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * Result of computing a day-based procedural term: the maturity date before and
 * after the working-day prorogation (CPC art. 181 para. 2). Both are kept because
 * the audit trail records what the plain calculation produced next to what the
 * lawyer is actually shown.
 */
final readonly class ProceduralTerm
{
    public function __construct(
        /** Maturity date as computed on the calendar, before prorogation. */
        public \DateTimeImmutable $rawEnd,
        /** Effective maturity date: prorogated to the next working day when needed. */
        public \DateTimeImmutable $end,
    ) {}
}
