<?php

declare(strict_types=1);

namespace App\Enum;

enum FilingAttemptOutcome: string
{
    case ACCEPTED = 'ACCEPTED';
    case TEMPORARY = 'TEMPORARY';
    case PERMANENT = 'PERMANENT';
    case SIZE = 'SIZE';
    case INTERNAL = 'INTERNAL';
    case UNCERTAIN = 'UNCERTAIN';

    public function label(): string
    {
        return 'enum.filing_attempt_outcome.' . $this->value;
    }
}
