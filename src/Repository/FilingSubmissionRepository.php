<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingEvidenceStatus;
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

    public function findOneByToken(string $token): ?FilingSubmission
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Transmitted submissions still waiting for a court receipt: the petition left
     * and nothing undid or cancelled it.
     *
     * @return list<FilingSubmission>
     */
    public function findReceiptOverdueCandidates(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.sentAt IS NOT NULL')
            ->andWhere('s.evidenceStatus = :awaiting')
            ->andWhere('s.deliveryStatus IN (:statuses)')
            ->setParameter('awaiting', FilingEvidenceStatus::AWAITING_RECEIPT)
            ->setParameter('statuses', [FilingDeliveryStatus::SENT, FilingDeliveryStatus::PARTIALLY_SENT, FilingDeliveryStatus::CLOSED_PARTIAL])
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Submissions transmitted since a date to an address of the given domain, the
     * court one or the override actually used.
     *
     * @return list<FilingSubmission>
     */
    public function findSentToDomainSince(string $domain, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.sentAt IS NOT NULL AND s.sentAt >= :since')
            ->andWhere('LOWER(s.toAddress) LIKE :suffix OR LOWER(s.intendedToAddress) LIKE :suffix')
            ->setParameter('since', $since)
            ->setParameter('suffix', '%@' . addcslashes(strtolower($domain), '%_'))
            ->orderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function hasActiveForCaseId(int $caseId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM filing_submission WHERE legal_case_id = ? AND active_lock = 1',
            [$caseId],
        );
    }
}
