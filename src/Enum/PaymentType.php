<?php

namespace App\Enum;

enum PaymentType: string
{
    case TAXA_JUDICIARA = 'taxa_judiciara';
    case COMISION_PLATFORMA = 'comision_platforma';

    public function label(): string
    {
        return match ($this) {
            self::TAXA_JUDICIARA => 'Taxă judiciară',
            self::COMISION_PLATFORMA => 'Comision platformă',
        };
    }
}
