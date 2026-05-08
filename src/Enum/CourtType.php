<?php

namespace App\Enum;

enum CourtType: string
{
    case JUDECATORIE = 'judecatorie';
    case TRIBUNAL = 'tribunal';

    public function label(): string
    {
        return 'enum.court_type.' . $this->value;
    }
}
