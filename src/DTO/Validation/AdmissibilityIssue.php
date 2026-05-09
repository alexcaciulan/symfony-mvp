<?php

namespace App\DTO\Validation;

use App\Enum\IssueSeverity;

final readonly class AdmissibilityIssue
{
    public function __construct(
        public IssueSeverity $severity,
        public string $code,
        public string $messageKey,
    ) {}
}
