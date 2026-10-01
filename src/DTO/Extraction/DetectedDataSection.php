<?php

declare(strict_types=1);

namespace App\DTO\Extraction;

/**
 * One party or the claim as the step 0 card shows it.
 *
 * `required` counts only the fields this case needs from that section, and
 * `filled` only those among them read without disagreement, so the fraction in
 * the header can always be counted off the rows below it.
 */
final readonly class DetectedDataSection
{
    /**
     * @param list<DetectedDataRow> $rows
     * @param list<string> $missing required fields with no value, in display order
     */
    public function __construct(
        public string $key,
        public int $step,
        public array $rows,
        public int $filled,
        public int $required,
        public array $missing,
        public bool $choiceRequired = false,
    ) {}

    public function percent(): int
    {
        return $this->required === 0 ? 100 : (int) round($this->filled / $this->required * 100);
    }

    public function hasData(): bool
    {
        return $this->rows !== [];
    }
}
