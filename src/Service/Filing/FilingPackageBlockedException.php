<?php

declare(strict_types=1);

namespace App\Service\Filing;

/**
 * The package cannot be built, for a reason the lawyer can act on. The message
 * key and parameters are shown as they are.
 */
final class FilingPackageBlockedException extends \RuntimeException
{
    /**
     * @param array<string, string> $messageParams
     */
    public function __construct(
        public readonly string $messageKey,
        public readonly array $messageParams = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($messageKey, 0, $previous);
    }
}
