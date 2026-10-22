<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\LegalCase;
use App\Repository\FilingSubmissionRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether a court filing currently holds the case. While it does, nothing that
 * feeds what is being sent may change: documents, the claim, the stamp duty or
 * the package. Answered once per case and request for the many permission checks
 * of a page; code that changes the lock, or decides under a row lock, asks fresh.
 */
final class FilingLockQuery implements ResetInterface
{
    /** @var array<int, bool> */
    private array $memo = [];

    public function __construct(
        private readonly FilingSubmissionRepository $submissions,
    ) {}

    public function isLocked(LegalCase $case): bool
    {
        $id = $case->getId();
        if ($id === null) {
            return false;
        }

        return $this->memo[$id] ??= $this->submissions->hasActiveForCaseId($id);
    }

    public function isLockedNow(LegalCase $case): bool
    {
        $id = $case->getId();
        if ($id === null) {
            return false;
        }
        unset($this->memo[$id]);

        return $this->isLocked($case);
    }

    public function forget(LegalCase $case): void
    {
        unset($this->memo[(int) $case->getId()]);
    }

    public function reset(): void
    {
        $this->memo = [];
    }
}
