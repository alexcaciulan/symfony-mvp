<?php

declare(strict_types=1);

namespace App\DTO\Case;

use App\Enum\FilingIssueCode;

/**
 * What stands between a case and the filing package: blockers refuse generation
 * and download, warnings are shown and let the lawyer go on.
 */
final readonly class FilingReadiness
{
    /**
     * @param list<FilingIssue> $blockers
     * @param list<FilingIssue> $warnings
     */
    public function __construct(
        public array $blockers = [],
        public array $warnings = [],
    ) {}

    public function isReady(): bool
    {
        return $this->blockers === [];
    }

    public function firstBlocker(): ?FilingIssue
    {
        return $this->blockers[0] ?? null;
    }

    public function hasBlocker(FilingIssueCode $code): bool
    {
        return self::contains($this->blockers, $code);
    }

    public function hasWarning(FilingIssueCode $code): bool
    {
        return self::contains($this->warnings, $code);
    }

    /**
     * @return list<string>
     */
    public function warningCodes(): array
    {
        return array_map(static fn (FilingIssue $issue): string => $issue->code->value, $this->warnings);
    }

    /**
     * @param list<FilingIssue> $issues
     */
    private static function contains(array $issues, FilingIssueCode $code): bool
    {
        foreach ($issues as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }
}
