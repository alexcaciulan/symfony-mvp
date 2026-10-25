<?php

declare(strict_types=1);

namespace App\Service\Court;

/** An address action the service did not perform; nothing was changed or stored. */
final class CourtEmailActionRefusedException extends \RuntimeException
{
    public function __construct(public readonly string $messageKey, ?\Throwable $previous = null)
    {
        parent::__construct($messageKey, 0, $previous);
    }
}
