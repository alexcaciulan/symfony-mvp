<?php

namespace App\Enum;

enum InterestKind: string
{
    case REMUNERATORIE = 'REMUNERATORIE';
    case PENALIZATOARE = 'PENALIZATOARE';

    public function label(): string
    {
        return 'enum.interest_kind.' . $this->value;
    }
}
