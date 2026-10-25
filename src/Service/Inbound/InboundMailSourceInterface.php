<?php

declare(strict_types=1);

namespace App\Service\Inbound;

/**
 * Where the poll reads court answers and bounces from. Acknowledging marks a
 * message as taken so the next poll does not fetch it again; it is called only
 * after the message is stored.
 */
interface InboundMailSourceInterface
{
    /** @return iterable<RawInboundMail> */
    public function fetch(int $limit): iterable;

    public function acknowledge(RawInboundMail $mail): void;

    public function name(): string;
}
