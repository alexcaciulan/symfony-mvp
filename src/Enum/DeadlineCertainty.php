<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether the stored deadline date can be relied upon. Derived at read time from
 * the case by {@see \App\Service\Deadline\DeadlineCertaintyResolver}, never stored:
 * the same deadline turns certain the moment the lawyer records the communication
 * date the term runs from.
 */
enum DeadlineCertainty: string
{
    /** The fact the term runs from is confirmed on the case. */
    case CERT = 'CERT';

    /** The generating fact is unconfirmed, so the date is a working assumption. */
    case ESTIMAT = 'ESTIMAT';

    public function label(): string
    {
        return 'enum.deadline_certainty.' . $this->value;
    }

    public function isEstimated(): bool
    {
        return $this === self::ESTIMAT;
    }
}
