<?php

namespace App\Enum;

enum UserType: string
{
    case PF = 'pf';
    case PJ = 'pj';
    case AVOCAT = 'avocat';
    case ADMIN = 'admin';

    public function label(): string
    {
        return 'enum.user_type.' . $this->value;
    }
}
