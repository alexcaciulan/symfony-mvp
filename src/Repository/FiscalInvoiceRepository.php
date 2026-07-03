<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FiscalInvoice;
use App\Entity\Invoice;
use App\Entity\User;
use App\Enum\EInvoiceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FiscalInvoice> */
class FiscalInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FiscalInvoice::class);
    }

    /** @return FiscalInvoice[] */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByInvoice(Invoice $invoice): ?FiscalInvoice
    {
        return $this->findOneBy(['invoice' => $invoice]);
    }

    /**
     * Issued invoices whose SPV status is still moving (queued/sent), for the
     * status poll.
     *
     * @return FiscalInvoice[]
     */
    public function findInTransit(int $limit = 100): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.eInvoiceStatus IN (:statuses)')
            ->andWhere('f.providerInvoiceId IS NOT NULL')
            ->setParameter('statuses', [EInvoiceStatus::PENDING, EInvoiceStatus::SENT])
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Mirrors for a paid invoice that never got a provider number (provider was
     * down at payment time), for the reconcile pass.
     *
     * @return FiscalInvoice[]
     */
    public function findUnissued(int $limit = 100): array
    {
        // Skip very recent drafts: they may still be in-flight in the worker
        // (issue succeeded, flush pending), so give the async path a grace window.
        return $this->createQueryBuilder('f')
            ->where('f.providerInvoiceId IS NULL')
            ->andWhere('f.invoice IS NOT NULL')
            ->andWhere('f.createdAt < :cutoff')
            ->setParameter('cutoff', new \DateTimeImmutable('-5 minutes'))
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
