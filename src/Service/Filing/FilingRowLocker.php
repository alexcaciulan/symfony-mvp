<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingSubmission;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one lock order every writer of a filing uses: the case, then the
 * submission, then its parts, each refreshed right after its lock. Taking them in
 * another order lets two transactions wait on each other (a deadlock rolls back
 * an acceptance the court server already gave), and reading the case before the
 * lock lets a decision be made on a status another transaction already changed.
 * Runs inside the caller's transaction.
 */
final class FilingRowLocker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function lock(FilingSubmission $submission): void
    {
        $case = $submission->getLegalCase();
        $this->em->lock($case, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($case);
        $this->em->lock($submission, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($submission);
        foreach ($submission->getParts() as $part) {
            $this->em->lock($part, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($part);
        }
    }
}
