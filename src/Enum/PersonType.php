<?php

namespace App\Enum;

enum PersonType: string
{
    case PF = 'PF';
    case PJ = 'PJ';

    public function label(): string
    {
        return 'enum.person_type.' . $this->value;
    }
}
