<?php

declare(strict_types=1);

namespace App\Message;

/** Sends one email of a court filing; the next part is dispatched only once this one is accepted. */
final readonly class SendFilingEmailPartMessage
{
    public function __construct(
        public int $partId,
    ) {}
}
