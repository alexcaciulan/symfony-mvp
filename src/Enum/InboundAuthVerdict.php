<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The technical check of the sender, read only from the Authentication-Results our
 * own receiving server wrote. TRUSTED_PASS means DMARC passed for a just.ro domain;
 * it says nothing about which registry sent it or what the message is worth.
 */
enum InboundAuthVerdict: string
{
    case TRUSTED_PASS = 'TRUSTED_PASS';
    case FAIL = 'FAIL';
    case ABSENT = 'ABSENT';

    public function label(): string
    {
        return 'enum.inbound_auth_verdict.' . $this->value;
    }
}
