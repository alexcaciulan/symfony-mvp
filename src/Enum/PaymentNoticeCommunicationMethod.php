<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How the summons is served on the debtor (CPC art. 1015 alin. 1). Both options
 * produce certain proof of receipt; their probative value differs (bailiff
 * service record vs. postal acknowledgment of receipt).
 */
enum PaymentNoticeCommunicationMethod: string
{
    case EXECUTOR = 'EXECUTOR';
    case POSTA_RCD = 'POSTA_RCD';

    public function label(): string
    {
        return 'enum.payment_notice_communication_method.' . $this->value;
    }
}
