<?php

declare(strict_types=1);

namespace App\Service\Inbound;

/** A message disposition notification (RFC 8098). */
final readonly class MdnResult
{
    public function __construct(
        public ?string $disposition,
        public ?string $originalMessageId,
        public ?string $finalRecipient,
    ) {}
}
