<?php

declare(strict_types=1);

namespace App\Service\Inbound;

/** One recipient of a delivery report (RFC 3464), or what a non-standard bounce text gives away. */
final readonly class DsnResult
{
    public function __construct(
        public ?string $action,
        public ?string $status,
        public ?int $smtpCode,
        public ?string $finalRecipient,
        public ?string $diagnostic,
        public bool $standard,
    ) {}

    public function isDelayed(): bool
    {
        return $this->action === 'delayed';
    }

    public function statusClass(): ?int
    {
        if ($this->status !== null && preg_match('/^([245])\./', $this->status, $m) === 1) {
            return (int) $m[1];
        }
        if ($this->smtpCode !== null) {
            return intdiv($this->smtpCode, 100);
        }

        return null;
    }
}
