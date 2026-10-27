<?php

declare(strict_types=1);

namespace App\Service\Filing;

/** Output of FilingSizePlanner. */
final readonly class FilingSizePlan
{
    /**
     * @param list<list<int>> $volumes            annex document ids per volume, in order
     * @param int             $volumesInFirstEmail leading volumes that travel with the petition
     * @param list<int>       $emailBytes         estimated raw bytes per email
     */
    public function __construct(
        public int $budget,
        public bool $needsCompression = false,
        public array $volumes = [],
        public int $volumesInFirstEmail = 0,
        public array $emailBytes = [],
        public ?string $error = null,
        public ?int $errorDocumentId = null,
    ) {}

    public function emailCount(): int
    {
        return count($this->emailBytes);
    }

    /** Email number (1-based) a volume (1-based) travels in. */
    public function emailForVolume(int $volume): int
    {
        return $volume <= $this->volumesInFirstEmail ? 1 : 1 + $volume - $this->volumesInFirstEmail;
    }
}
