<?php

declare(strict_types=1);

namespace App\Service\Filing;

/**
 * The rows of the signing card plus what the card decides from all of them.
 */
final readonly class FilingPartRows
{
    /**
     * @param list<FilingPartRow> $rows
     */
    public function __construct(
        public array $rows,
        public bool $uploadsOpen,
        public bool $anySignature,
        public bool $collapsed,
    ) {}
}
