<?php

declare(strict_types=1);

namespace App\Enum;

enum CloseReason: string
{
    case PAID = 'PAID';
    case PARTIAL = 'PARTIAL';
    case ABANDONED = 'ABANDONED';
    case INSOLVENT_EXECUTARE = 'INSOLVENT_EXECUTARE';

    public function label(): string
    {
        return 'enum.close_reason.' . $this->value;
    }

    /**
     * Maps a close reason to its corresponding workflow transition name.
     * PAID/PARTIAL → successful closure; ABANDONED/INSOLVENT_EXECUTARE → closure
     * without recovery. Insolvency is recorded only as an enforcement-phase
     * outcome (CPC art. 781 et seq.), never as an OP closure (the OP itself
     * produces a writ of execution, it cannot fail for debtor insolvency).
     */
    public function targetTransition(): string
    {
        return match ($this) {
            self::PAID, self::PARTIAL => 'inchide_succes',
            self::ABANDONED, self::INSOLVENT_EXECUTARE => 'inchide_fara_recuperare',
        };
    }
}
