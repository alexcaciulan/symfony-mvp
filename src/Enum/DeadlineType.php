<?php

declare(strict_types=1);

namespace App\Enum;

enum DeadlineType: string
{
    case RASPUNS_SOMATIE = 'RASPUNS_SOMATIE';
    case DEPUNERE_CERERE = 'DEPUNERE_CERERE';
    case JUDECATA = 'JUDECATA';
    case CERERE_IN_ANULARE = 'CERERE_IN_ANULARE';
    case PRESCRIPTIE = 'PRESCRIPTIE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return 'enum.deadline_type.' . $this->value;
    }

    /**
     * Prioritatea default per tip termen, aliniată cu ANALIZA-FLUXURI secțiunea 8.
     * RASPUNS_SOMATIE = HIGH (15 zile, CPC art. 1015 alin. 1 — ratarea înseamnă
     * întârziere depunere cerere OP). CERERE_IN_ANULARE + PRESCRIPTIE = CRITICAL
     * (impact juridic direct: ratare → pierdere drept material sau ordonanță
     * neînvestită cu titlu executoriu).
     */
    public function defaultPriority(): DeadlinePriority
    {
        return match ($this) {
            self::RASPUNS_SOMATIE => DeadlinePriority::HIGH,
            self::DEPUNERE_CERERE => DeadlinePriority::HIGH,
            self::JUDECATA => DeadlinePriority::MEDIUM,
            self::CERERE_IN_ANULARE => DeadlinePriority::CRITICAL,
            self::PRESCRIPTIE => DeadlinePriority::CRITICAL,
            self::OTHER => DeadlinePriority::MEDIUM,
        };
    }
}
