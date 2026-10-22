<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How the petition reached the court. EMAIL is set when the platform transmitted
 * the signed package, on the lawyer's request, to the court address the lawyer
 * validated; every other channel is the lawyer's declaration. In neither case
 * does the platform observe the registration, so the filing date stays the
 * lawyer's declaration. It matters because the date of filing follows the channel
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
