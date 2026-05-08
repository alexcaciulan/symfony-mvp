<?php

namespace App\Enum;

enum DeadlineType: string
{
    case RASPUNS_SOMATIE = 'RASPUNS_SOMATIE';
    case DEPUNERE_CERERE = 'DEPUNERE_CERERE';
    case JUDECATA = 'JUDECATA';
    case CONTESTATIE = 'CONTESTATIE';
    case PRESCRIPTIE = 'PRESCRIPTIE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return 'enum.deadline_type.' . $this->value;
    }

    public function defaultPrioritate(): DeadlinePriority
    {
        return match ($this) {
            self::RASPUNS_SOMATIE => DeadlinePriority::MEDIUM,
            self::DEPUNERE_CERERE => DeadlinePriority::HIGH,
            self::JUDECATA => DeadlinePriority::HIGH,
            self::CONTESTATIE => DeadlinePriority::CRITICAL,
            self::PRESCRIPTIE => DeadlinePriority::CRITICAL,
            self::OTHER => DeadlinePriority::MEDIUM,
        };
    }
}
