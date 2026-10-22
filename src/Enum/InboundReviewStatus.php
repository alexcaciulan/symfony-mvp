<?php

declare(strict_types=1);

namespace App\Enum;

enum InboundReviewStatus: string
{
    case NONE = 'NONE';
    case PENDING = 'PENDING';
    case RESOLVED = 'RESOLVED';

    public function label(): string
    {
        return 'enum.inbound_review_status.' . $this->value;
    }
}
