<?php

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Subscription> */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findActiveForUser(User $user): ?Subscription
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', SubscriptionStatus::ACTIVE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The user's currently usable subscription: a status that entitles case
     * activation ({@see SubscriptionStatus::isUsable()}, i.e. ACTIVE/TRIAL/CANCELED)
     * AND still within its billing period. This is what gates `trimite_somatie`.
     * CANCELED stays current until currentPeriodEnd, then the date guard drops it.
     */
    public function findCurrentForUser(User $user, ?\DateTimeImmutable $now = null): ?Subscription
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.status IN (:statuses)')
            ->andWhere('s.currentPeriodEnd >= :now')
            ->setParameter('user', $user)
            ->setParameter('statuses', [
                SubscriptionStatus::ACTIVE,
                SubscriptionStatus::TRIAL,
                SubscriptionStatus::CANCELED,
            ])
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->orderBy('s.currentPeriodEnd', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
