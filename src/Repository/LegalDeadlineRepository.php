<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LegalCase;
use App\Entity\LegalDeadline;
use App\Entity\User;
use App\Enum\DeadlineType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<LegalDeadline> */
class LegalDeadlineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LegalDeadline::class);
    }

    /** @return LegalDeadline[] */
    public function findByCase(LegalCase $case): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.legalCase = :case')
            ->setParameter('case', $case)
            ->orderBy('d.deadlineDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Idempotency lookup pentru DeadlineCreationSubscriber: termenele automate sunt unice per (dosar, tip). */
    public function findOneByCaseAndType(LegalCase $case, DeadlineType $type): ?LegalDeadline
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.legalCase = :case')
            ->andWhere('d.type = :type')
            ->setParameter('case', $case)
            ->setParameter('type', $type)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Idempotency lookup pentru termenele detectate din portal (Pas 6.1
     * MonitoringEventApplier): un termen JUDECATA poate exista de mai multe ori
     * pe un dosar (ședințe multiple), deci dedup-ul se face pe (dosar, tip, dată),
     * NU doar pe (dosar, tip) ca {@see self::findOneByCaseAndType()}.
     */
    public function findOneByCaseTypeAndDate(LegalCase $case, DeadlineType $type, \DateTimeInterface $date): ?LegalDeadline
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.legalCase = :case')
            ->andWhere('d.type = :type')
            ->andWhere('d.deadlineDate = :date')
            ->setParameter('case', $case)
            ->setParameter('type', $type)
            ->setParameter('date', $date->format('Y-m-d'))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return LegalDeadline[] */
    public function findUpcomingByUser(User $user, int $days = 30, ?int $limit = null): array
    {
        $cutoff = (new \DateTimeImmutable())->modify("+{$days} days");

        $qb = $this->createQueryBuilder('d')
            ->join('d.legalCase', 'lc')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('d.completed = false')
            ->andWhere('d.deadlineDate <= :cutoff')
            ->setParameter('user', $user)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('d.deadlineDate', 'ASC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Count upcoming non-completed deadlines within $days for a user.
     * Used by dashboard KPI "Termene urgente".
     */
    public function countUpcomingByUser(User $user, int $days = 7): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("+{$days} days");

        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->join('d.legalCase', 'lc')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('d.completed = false')
            ->andWhere('d.deadlineDate <= :cutoff')
            ->setParameter('user', $user)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
