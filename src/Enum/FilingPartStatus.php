<?php

declare(strict_types=1);

namespace App\Enum;

/** One email of a filing: parts leave in order, each only after the previous one was accepted. */
enum FilingPartStatus: string
{
    case PENDING = 'PENDING';
    case SENDING = 'SENDING';
    case ACCEPTED = 'ACCEPTED';
    case RETRY_SCHEDULED = 'RETRY_SCHEDULED';
    case FAILED_TEMPORARY_EXHAUSTED = 'FAILED_TEMPORARY_EXHAUSTED';
    case FAILED_PERMANENT = 'FAILED_PERMANENT';
    case FAILED_SIZE = 'FAILED_SIZE';
    case FAILED_INTERNAL = 'FAILED_INTERNAL';
    /** Found mid-send after a crash: whether the server took it is unknown. */
    case UNCERTAIN = 'UNCERTAIN';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return 'enum.filing_part_status.' . $this->value;
    }

    /** Not sent yet and still allowed to go. */
    public function isCancellable(): bool
    {
        return $this === self::PENDING || $this === self::RETRY_SCHEDULED;
    }
}
