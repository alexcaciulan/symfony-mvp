<?php

namespace App\Enum;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'În așteptare',
            self::COMPLETED => 'Finalizată',
            self::FAILED => 'Eșuată',
            self::REFUNDED => 'Rambursată',
        };
    }
}
