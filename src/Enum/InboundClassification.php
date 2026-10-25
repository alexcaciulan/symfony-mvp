<?php

declare(strict_types=1);

namespace App\Enum;

/** What a received message is, as far as the headers and the delivery reports say. */
enum InboundClassification: string
{
    /** An automatic answer of the court mailbox acknowledging receipt; never a registration. */
    case RECEIPT = 'RECEIPT';
    /** A message disposition notification (RFC 8098): a mail client displayed the message. */
    case READ_RECEIPT = 'READ_RECEIPT';
    case BOUNCE_TEMPORARY = 'BOUNCE_TEMPORARY';
    case BOUNCE_PERMANENT = 'BOUNCE_PERMANENT';
    case BOUNCE_SIZE = 'BOUNCE_SIZE';
    case DELAYED = 'DELAYED';
    case AUTO_REPLY_OTHER = 'AUTO_REPLY_OTHER';
    case HUMAN_REPLY = 'HUMAN_REPLY';
    case SPAM = 'SPAM';
    case UNKNOWN = 'UNKNOWN';

    public function label(): string
    {
        return 'enum.inbound_classification.' . $this->value;
    }

    public function isBounce(): bool
    {
        return in_array($this, [self::BOUNCE_PERMANENT, self::BOUNCE_SIZE, self::BOUNCE_TEMPORARY, self::DELAYED], true);
    }

    /** Kept only for the purge delay: nothing ties these to a case. */
    public function isPurgeable(): bool
    {
        return $this === self::UNKNOWN || $this === self::SPAM;
    }
}
