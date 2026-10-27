<?php

declare(strict_types=1);

namespace App\Service\Inbound;

/** A receipt upload that was not accepted; nothing was stored. */
final class ManualReceiptRefusedException extends \RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($messageKey, 0, $previous);
    }
}
