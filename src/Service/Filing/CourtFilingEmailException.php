<?php

declare(strict_types=1);

namespace App\Service\Filing;

/** The court email cannot be built as decided; the key names what is missing. */
final class CourtFilingEmailException extends \RuntimeException
{
    public function __construct(public readonly string $messageKey)
    {
        parent::__construct($messageKey);
    }
}
