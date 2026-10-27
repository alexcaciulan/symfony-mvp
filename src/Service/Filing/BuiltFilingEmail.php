<?php

declare(strict_types=1);

namespace App\Service\Filing;

use Symfony\Component\Mailer\Envelope;

/** One court email serialized once: these bytes are written to disk and then sent, unchanged. */
final readonly class BuiltFilingEmail
{
    public function __construct(
        public string $bytes,
        public string $sha256,
        public int $encodedBytes,
        public string $messageId,
        public Envelope $envelope,
    ) {}
}
