<?php

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use App\Enum\InvoiceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Invoice> */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /** @return Invoice[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.user = :user')
            ->setParameter('user', $user)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Unsettled invoices of one type for a given subscription, oldest first.
     *
     * Scoping to the subscription is what makes checkout deterministic: picking
     * the user's newest PENDING invoice instead breaks down when two were raised
     * in the same second, and can send the user to pay the wrong amount.
     *
     * Callers that go on to WRITE these rows must pass `$forUpdate` and run inside
     * a transaction. Without the lock, a settlement committing between this read
     * and the write would be silently overwritten: a paid invoice flipped to
     * CANCELED, with its fiscal invoice already issued. The lock is opt-in because
     * read-only callers run outside a transaction, where Doctrine refuses it.
     *
     * @return Invoice[]
     */
    public function findPendingByType(Subscription $subscription, InvoiceType $type, bool $forUpdate = false): array
    {
        $query = $this->createQueryBuilder('i')
            ->where('i.subscription = :subscription')
            ->andWhere('i.type = :type')
            ->andWhere('i.status = :status')
            ->setParameter('subscription', $subscription)
            ->setParameter('type', $type)
            ->setParameter('status', InvoiceStatus::PENDING)
            ->orderBy('i.id', 'ASC')
            ->getQuery();

        if ($forUpdate) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $query->getResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.status = :status')
            ->setParameter('status', InvoiceStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * PENDING invoices older than `$before` that already carry a gateway
     * transaction reference (externalId = ntpID), i.e. a payment was started but
     * never confirmed via IPN. These are the reconciliation candidates whose
     * live status is re-queried from the gateway.
     *
     * @return Invoice[]
     */
    public function findStalePending(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.status = :status')
            ->andWhere('i.externalId IS NOT NULL')
            ->andWhere('i.createdAt <= :before')
            ->setParameter('status', InvoiceStatus::PENDING)
            ->setParameter('before', $before)
            ->orderBy('i.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
