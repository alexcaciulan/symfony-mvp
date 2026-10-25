<?php

declare(strict_types=1);

namespace App\Enum;

/** Only CERTAIN lets a message change a submission or a case; PROBABLE goes to review. */
enum InboundMatchConfidence: string
{
    case CERTAIN = 'CERTAIN';
    case PROBABLE = 'PROBABLE';

    public function label(): string
    {
        return 'enum.inbound_match_confidence.' . $this->value;
    }
}
