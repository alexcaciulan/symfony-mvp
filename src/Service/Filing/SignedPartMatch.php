<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Enum\SignatureStatus;

/** How a signed upload relates to the file the platform generated for that part. */
final readonly class SignedPartMatch
{
    public const METHOD_PREFIX = 'prefix';
    public const METHOD_TEXT = 'text';

    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public SignatureStatus $status,
        public ?string $method = null,
        public array $warnings = [],
    ) {}
}
