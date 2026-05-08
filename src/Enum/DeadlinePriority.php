<?php

namespace App\Enum;

enum DeadlinePriority: string
{
    case LOW = 'LOW';
    case MEDIUM = 'MEDIUM';
    case HIGH = 'HIGH';
    case CRITICAL = 'CRITICAL';

    public function label(): string
    {
        return 'enum.deadline_priority.' . $this->value;
    }

    public function color(): string
    {
        return match ($this) {
            self::LOW => 'slate',
            self::MEDIUM => 'sky',
            self::HIGH => 'amber',
            self::CRITICAL => 'red',
        };
    }
}
