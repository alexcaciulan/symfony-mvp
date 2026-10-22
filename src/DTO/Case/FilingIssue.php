<?php

declare(strict_types=1);

namespace App\DTO\Case;

use App\Enum\FilingIssueCode;

final readonly class FilingIssue
{
    /**
     * @param array<string, string> $params translation parameters, e.g. dates
     * @param ?int $documentId the document the issue is about, so the UI can open its edit form
     */
    public function __construct(
        public FilingIssueCode $code,
        public array $params = [],
        public ?int $documentId = null,
    ) {}
}
