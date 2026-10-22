<?php

declare(strict_types=1);

namespace App\Service\Inbound;

/** One message as a source handed it over, before anything is parsed or stored. */
final readonly class RawInboundMail
{
    public function __construct(
        public string $source,
        public string $sourceId,
        public string $raw,
        public \DateTimeImmutable $receivedAt,
    ) {}
}
