<?php

namespace App\Enum;

enum ExtractionStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return 'enum.extraction_status.' . $this->value;
    }
}
