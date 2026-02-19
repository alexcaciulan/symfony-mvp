<?php

namespace App\Repository;

use App\Entity\Payment;
use App\Enum\PaymentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Payment> */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function sumCompletedCurrentMonth(): string
    {
        $start = new \DateTimeImmutable('first day of this month midnight');
        $end = new \DateTimeImmutable('first day of next month midnight');

        return (string) ($this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amount), 0)')
            ->where('p.status = :status')
            ->andWhere('p.createdAt >= :start')
            ->andWhere('p.createdAt < :end')
            ->setParameter('status', PaymentStatus::COMPLETED)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult());
    }
}
