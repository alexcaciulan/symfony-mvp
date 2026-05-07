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
    public function findUpcomingByUser(User $user, int $days = 30): array
    {
        $cutoff = (new \DateTimeImmutable())->modify("+{$days} days");

        return $this->createQueryBuilder('d')
            ->join('d.legalCase', 'lc')
            ->where('lc.user = :user')
            ->andWhere('lc.deletedAt IS NULL')
            ->andWhere('d.completed = false')
            ->andWhere('d.deadlineDate <= :cutoff')
            ->setParameter('user', $user)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('d.deadlineDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
