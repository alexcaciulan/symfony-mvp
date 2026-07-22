<?php

namespace App\Enum;

enum ExtractionStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    /**
     * No strategy ran because the user's privacy settings forbid it. Distinct
     * from FAILED, which claims something broke.
     */
    case SKIPPED_BY_POLICY = 'SKIPPED_BY_POLICY';

    /**
     * The attempt failed for a transient reason and the message is queued for
     * another try. Without it the document would read FAILED in the UI while
     * the retry is still pending, then flip to COMPLETED.
     */
    case PENDING_RETRY = 'PENDING_RETRY';

    public function label(): string
    {
        return 'enum.extraction_status.' . $this->value;
    }

    /**
     * Whether the document has reached a state that will not change without a
     * new user action. PENDING_RETRY is not terminal: the queue will revisit it.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::SKIPPED_BY_POLICY => true,
            self::PENDING, self::PROCESSING, self::PENDING_RETRY => false,
        };
    }
}
