<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingEmailPart;
use App\Entity\FilingPackage;
use App\Entity\FilingSubmission;
use App\Entity\User;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartRole;
use App\Enum\FilingPartStatus;

/**
 * What can be done after the court's server refused a court email for its size.
 * The limit is a technical one of the receiving mailbox, not a legal one.
 *
 * Only a refused first email that carries annexes, with nothing accepted, can be
 * helped by splitting the annexes again: the petition, its index and the power of
 * attorney keep their signed files, the new annex volumes are signed again by the
 * lawyer, and a new submission is requested. Once the petition was accepted, the
 * remaining annexes are filed on another channel.
 */
final class FilingResplitService
{
    /** Below this per-email budget a new split is refused: the annexes are filed another way. */
    public const MIN_BUDGET_BYTES = 1_048_576;

    public function __construct(
        private readonly FilingPackageBuilder $builder,
        private readonly int $filingMaxEmailBytes,
    ) {}

    /** Half of what failed (or of the configured limit, when smaller). */
    public function newBudget(FilingEmailPart $failed): int
    {
        return intdiv(min($this->filingMaxEmailBytes, $failed->getEncodedBytes() ?? $this->filingMaxEmailBytes), 2);
    }

    public function isResplittable(FilingSubmission $submission): bool
    {
        $first = $submission->getPart(1);

        return $first !== null
            && $first->getStatus() === FilingPartStatus::FAILED_SIZE
            && $submission->countAcceptedParts() === 0
            && $first->carries(FilingPartRole::ANNEX_BUNDLE)
            && $this->newBudget($first) >= self::MIN_BUDGET_BYTES
            && $submission->getPackage()->getStatus() === FilingPackageStatus::SIGNED;
    }

    /**
     * A new package version with the annexes split for half the size that failed.
     *
     * @throws FilingPackageBlockedException
     */
    public function resplitAfterSizeFailure(FilingSubmission $submission, User $user): FilingPackage
    {
        if ($submission->getLegalCase()->getUser() !== $user) {
            throw new FilingPackageBlockedException('filing.send.blocker.not_owner');
        }
        if ($submission->isActive() || !$this->isResplittable($submission)) {
            throw new FilingPackageBlockedException('filing.resplit.error.not_allowed');
        }

        return $this->builder->rebuildAnnexVolumes($submission->getPackage(), $this->newBudget($submission->getPart(1) ?? throw new \LogicException('A submission has a first email.')));
    }
}
