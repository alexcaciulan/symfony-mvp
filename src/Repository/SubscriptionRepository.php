<?php

namespace App\Repository;

use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
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
            // Newest first, so overlapping rows resolve the same way here as in
            // findCurrentForUser instead of following the storage engine's order.
            ->orderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Active subscriptions whose billing period has ended by `$on` and that hold
     * a saved recurring token, i.e. candidates for an off-session renewal charge.
     * CANCELED is excluded (it must not renew); a null token is excluded (nothing
     * to charge, the subscription simply lapses).
     *
     * @return Subscription[]
     */
    public function findDueForRenewal(\DateTimeImmutable $on): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.currentPeriodEnd <= :on')
            ->andWhere('s.recurringToken IS NOT NULL')
            ->setParameter('status', SubscriptionStatus::ACTIVE)
            ->setParameter('on', $on)
            ->orderBy('s.currentPeriodEnd', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * ACTIVE subscriptions created before `$before` whose checkout was started but
     * never completed: an invoice is still open and none has ever been settled.
     * They hold the user's only subscription slot hostage, because findCurrentForUser
     * keeps returning them and subscribeToPlan then refuses any other plan.
     *
     * Both halves of the predicate matter. Requiring no PAID invoice keeps failed
     * renewals out of scope, since those belong to dunning
     * ({@see \App\Service\Billing\SubscriptionRenewalService}). Requiring an open
     * invoice keeps seeded or manually created subscriptions, which have no invoices
     * at all, from being released as if their payment had been abandoned.
     *
     * @return Subscription[]
     */
    public function findAbandonedCheckoutsCreatedBefore(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.createdAt <= :before')
            ->andWhere('NOT EXISTS (SELECT 1 FROM App\Entity\Invoice paid WHERE paid.subscription = s AND paid.status = :paid)')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\Invoice open WHERE open.subscription = s AND open.status = :pending)')
            ->setParameter('status', SubscriptionStatus::ACTIVE)
            ->setParameter('before', $before)
            ->setParameter('paid', InvoiceStatus::PAID)
            ->setParameter('pending', InvoiceStatus::PENDING)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
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
            // Tie-break: two rows can share a period end (a trial converted the
            // same day), and picking a different one per query would split slot
            // consumption from billing.
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
