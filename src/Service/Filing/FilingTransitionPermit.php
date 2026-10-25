<?php

declare(strict_types=1);

namespace App\Service\Filing;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Lets the workflow guard tell the platform's own `depune_cerere` apart from any
 * other path while a submission holds the case. Guard events carry no context,
 * so the submission that may move the case is named here for the duration of
 * the transition.
 */
final class FilingTransitionPermit implements ResetInterface
{
    private ?int $submissionId = null;

    public function grant(int $submissionId): void
    {
        $this->submissionId = $submissionId;
    }

    public function revoke(): void
    {
        $this->submissionId = null;
    }

    public function allows(?int $submissionId): bool
    {
        return $submissionId !== null && $this->submissionId === $submissionId;
    }

    public function reset(): void
    {
        $this->revoke();
    }
}
