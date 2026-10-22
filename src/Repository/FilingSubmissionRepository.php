<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FilingSubmission>
 */
class FilingSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FilingSubmission::class);
    }

    /** The submission that holds the case, if any. */
    public function findActiveForCase(LegalCase $case): ?FilingSubmission
    {
        return $this->findOneBy(['legalCase' => $case, 'activeLock' => 1]);
    }

    public function findLatestForCase(LegalCase $case): ?FilingSubmission
    {
        return $this->findOneBy(['legalCase' => $case], ['id' => 'DESC']);
    }

    public function hasActiveForCaseId(int $caseId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM filing_submission WHERE legal_case_id = ? AND active_lock = 1',
            [$caseId],
        );
    }
}
