<?php

declare(strict_types=1);

namespace App\Message;

/** Classify, match and apply one stored inbound message. */
final readonly class ProcessInboundMailMessage
{
    public function __construct(
        public int $inboundMailId,
    ) {}
}
