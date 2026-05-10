<?php

namespace App\Repository;

use App\Entity\LegalDeadline;
use App\Entity\User;
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
