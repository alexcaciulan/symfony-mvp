<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Notification> */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /** Idempotency lookup: returns the row already emitted for this dedup key, if any. */
    public function findOneByDedupKey(string $dedupKey): ?Notification
    {
        return $this->findOneBy(['dedupKey' => $dedupKey]);
    }

    public function countUnreadByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Notification[] */
    public function findRecentByUser(User $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Ownership-guarded fetch: returns null when the id belongs to another user. */
    public function findOneByIdAndUser(int $id, User $user): ?Notification
    {
        return $this->createQueryBuilder('n')
            ->where('n.id = :id')
            ->andWhere('n.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Bulk mark-all-read in one statement; returns the number of rows affected. */
    public function markAllReadByUser(User $user): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.isRead', ':read')
            ->set('n.readAt', ':now')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('read', true)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Retention: notifications are transient pointers (the durable record lives in
     * the case, its deadlines, and the audit log), so old rows can be pruned. Read
     * rows older than $readCutoff go; anything older than $anyCutoff goes even if
     * still unread (a hard safety cap so the table cannot grow unbounded).
     */
    public function pruneObsolete(\DateTimeImmutable $readCutoff, \DateTimeImmutable $anyCutoff): int
    {
        return (int) $this->obsoleteQuery($readCutoff, $anyCutoff)->delete()->getQuery()->execute();
    }

    /** Count what pruneObsolete() would delete, for a dry run. */
    public function countObsolete(\DateTimeImmutable $readCutoff, \DateTimeImmutable $anyCutoff): int
    {
        return (int) $this->obsoleteQuery($readCutoff, $anyCutoff)
            ->select('COUNT(n.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function obsoleteQuery(\DateTimeImmutable $readCutoff, \DateTimeImmutable $anyCutoff): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->where('(n.isRead = true AND n.createdAt < :readCutoff) OR n.createdAt < :anyCutoff')
            ->setParameter('readCutoff', $readCutoff)
            ->setParameter('anyCutoff', $anyCutoff);
    }
}
