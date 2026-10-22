<?php

declare(strict_types=1);

namespace App\Service\Filing;

/** The filing was not queued; the key says why, in terms the lawyer can act on. */
final class FilingSendBlockedException extends \RuntimeException
{
    /** @param list<string> $blockers */
    public function __construct(
        public readonly string $messageKey,
        public readonly array $blockers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($messageKey, 0, $previous);
    }
}
