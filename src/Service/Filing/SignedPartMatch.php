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
     * Notes describe what was tolerated in a probable match (added text, appended
     * pages, a missing part token) with page numbers and counts only, keyed for
     * the translator the way FilingSendGuard warnings are.
     *
     * @param list<string>                                                $warnings
     * @param list<array{key: string, params: array<string, string|int>}> $notes
     */
    public function __construct(
        public SignatureStatus $status,
        public ?string $method = null,
        public array $warnings = [],
        public array $notes = [],
    ) {}
}
