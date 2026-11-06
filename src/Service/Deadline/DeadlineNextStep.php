<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * What a row suggests doing next. A suggestion is text, never a button: nothing on the
 * agenda generates a document. `$code` is null while the debtor's term still runs, when
 * the row stays silent and only the panel states the sentence.
 */
final readonly class DeadlineNextStep
{
    public const SUMMONS = 'summons';
    public const COMMUNICATION_DATE = 'communication_date';
    public const PROOF = 'proof';
    public const PAYMENT_ORDER = 'payment_order';
    public const FILING_PACKAGE = 'filing_package';
    public const FILING = 'filing';

    public function __construct(
        public ?string $code,
        /** Translation key of the sentence the panel prints. */
        public string $sentenceKey,
    ) {}
}
