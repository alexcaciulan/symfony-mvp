<?php

declare(strict_types=1);

namespace App\Enum;

enum CloseReason: string
{
    case PAID = 'PAID';
    case PARTIAL = 'PARTIAL';
    case INSOLVENT = 'INSOLVENT';
    case ABANDONED = 'ABANDONED';

    public function label(): string
    {
        return 'enum.close_reason.' . $this->value;
    }

    /**
     * Maps a close reason to its corresponding workflow transition name.
     * PAID/PARTIAL → successful closure; INSOLVENT/ABANDONED → partial-insolvent closure.
     */
    public function targetTransition(): string
    {
        return match ($this) {
            self::PAID, self::PARTIAL => 'inchide_succes',
            self::INSOLVENT, self::ABANDONED => 'inchide_insolvabil',
        };
    }
}
