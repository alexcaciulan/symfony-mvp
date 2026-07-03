<?php

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\InvoiceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
