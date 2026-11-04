<?php

declare(strict_types=1);

namespace App\DTO\Wizard;

/**
 * Whether a step may advance past what the documents disagree about, and,
 * when it may not, the message that says why.
 */
final readonly class ConflictSettlement
{
    public function __construct(
        public bool $settled,
        public ?string $flashKey = null,
    ) {}

    public static function settled(): self
    {
        return new self(true);
    }

    public static function refused(string $flashKey): self
    {
        return new self(false, $flashKey);
    }
}
