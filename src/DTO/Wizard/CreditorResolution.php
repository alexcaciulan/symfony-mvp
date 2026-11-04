<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

use App\Entity\Creditor;

/**
 * The creditor a submitted case names, and where it came from: created with
 * the case, or taken from the library as it was or updated with the step's
 * data.
 */
final readonly class CreditorResolution
{
    public function __construct(
        public Creditor $creditor,
        public bool $reused,
        public string $source,
    ) {}
}
