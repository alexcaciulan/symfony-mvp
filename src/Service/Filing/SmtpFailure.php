<?php

declare(strict_types=1);

namespace App\Service\Filing;

/**
 * A classified SMTP failure. The message keeps only the code, the enhanced status,
 * the stage and a shortened server text without email addresses, so it can be
 * stored and shown without carrying a recipient or debtor data.
 */
final readonly class SmtpFailure
{
    public function __construct(
        public SmtpFailureKind $kind,
        public ?int $smtpCode,
        public ?string $enhancedStatus,
        public string $stage,
        public bool $invalidatesCourtAddress,
        public string $safeMessage,
    ) {}
}
