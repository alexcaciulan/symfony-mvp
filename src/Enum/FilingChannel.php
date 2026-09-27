<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How the petition reached the court. Recorded from the lawyer's own confirmation:
 * the platform files nothing and cannot observe delivery, so this is a declaration,
 * not an observation. It matters because the date of filing follows the channel
 * (CPC art. 183: mail counts from handover, electronic channels from registration
 * at the court), and because the stamp-duty proof only travels with the petition
 * on the electronic registry.
 */
enum FilingChannel: string
{
    case REJUST = 'REJUST';
    case REGISTRATURA = 'REGISTRATURA';
    case POSTA = 'POSTA';
    case CURIER = 'CURIER';
    case EMAIL = 'EMAIL';

    public function label(): string
    {
        return 'enum.filing_channel.' . $this->value;
    }
}
