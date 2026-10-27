<?php

declare(strict_types=1);

namespace App\Service\Filing;

final class FilingDateRefusedException extends \RuntimeException
{
    public function __construct(public readonly string $messageKey)
    {
        parent::__construct($messageKey);
    }
}
