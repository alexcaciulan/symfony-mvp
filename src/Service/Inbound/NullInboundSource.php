<?php

declare(strict_types=1);

namespace App\Service\Inbound;

/** No mailbox configured: nothing is read, and the poll does not count as a success. */
final class NullInboundSource implements InboundMailSourceInterface
{
    public const NAME = 'none';

    public function fetch(int $limit): iterable
    {
        return [];
    }

    public function acknowledge(RawInboundMail $mail): void {}

    public function name(): string
    {
        return self::NAME;
    }
}
