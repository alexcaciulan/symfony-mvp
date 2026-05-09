<?php

namespace App\Enum;

enum AnafStatus: string
{
    case ACTIV = 'ACTIV';
    case INACTIV = 'INACTIV';
    case RADIAT = 'RADIAT';

    public function label(): string
    {
        return 'enum.anaf_status.' . $this->value;
    }
}
