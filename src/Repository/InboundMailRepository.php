<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FilingSubmission;
use App\Entity\InboundMail;
use App\Enum\InboundClassification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InboundMail>
 */
class InboundMailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboundMail::class);
    }

    public function findOneBySource(string $source, string $sourceId): ?InboundMail
    {
        return $this->findOneBy(['source' => $source, 'sourceId' => $sourceId]);
    }

    /** The same message delivered twice (another source id, same bytes). */
    public function findOneBySha256AndMessageId(string $sha256, ?string $messageId): ?InboundMail
    {
        return $this->findOneBy(['rawSha256' => $sha256, 'messageId' => $messageId]);
    }

    /**
     * Unattributed and spam messages past their purge date, never one tied to a
     * submission or a case.
     *
     * @return list<InboundMail>
     */
    public function findPurgeable(\DateTimeImmutable $now, int $limit = 500): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.classification IN (:classes)')
            ->andWhere('m.purgeAfter IS NOT NULL AND m.purgeAfter < :now')
            ->andWhere('m.matchedSubmission IS NULL AND m.legalCase IS NULL')
            ->setParameter('classes', [InboundClassification::UNKNOWN, InboundClassification::SPAM])
            ->setParameter('now', $now)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Stored before the cutoff and never processed, not queued again since the
     * cutoff and below the requeue cap: their processing message was lost or failed.
     *
     * @return list<InboundMail>
     */
    public function findUnprocessedBefore(\DateTimeImmutable $cutoff, int $maxRequeues, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.processedAt IS NULL')
            ->andWhere('m.createdAt < :cutoff')
            ->andWhere('m.requeuedAt IS NULL OR m.requeuedAt < :cutoff')
            ->andWhere('m.requeueCount < :max')
            ->setParameter('cutoff', $cutoff)
            ->setParameter('max', $maxRequeues)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Messages stored before the cutoff whose processing has still not completed, requeued or not. */
    public function countUnprocessedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.processedAt IS NULL')
            ->andWhere('m.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<InboundMail> */
    public function findForSubmission(FilingSubmission $submission): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.matchedSubmission = :s')
            ->setParameter('s', $submission)
            ->orderBy('m.receivedAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
