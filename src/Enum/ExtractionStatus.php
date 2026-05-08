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
        return match ($this) {
            self::PENDING => 'În așteptare',
            self::PROCESSING => 'În procesare',
            self::COMPLETED => 'Finalizată',
            self::FAILED => 'Eșuată',
        };
    }
}
