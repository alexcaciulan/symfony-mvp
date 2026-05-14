<?php

declare(strict_types=1);

namespace App\Enum;

enum PersonType: string
{
    // Order matters — wizard templates render the cards in enum order and
    // pre-select the first case. PJ ships first because B2B claims dominate
    // the OP workflow (CPC art. 1015 — commercial relationships).
    case PJ = 'PJ';
    case PF = 'PF';

    public function label(): string
    {
        return 'enum.person_type.' . $this->value;
    }
}
