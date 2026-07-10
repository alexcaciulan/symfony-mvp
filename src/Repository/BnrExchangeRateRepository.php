<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BnrExchangeRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BnrExchangeRate> */
class BnrExchangeRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BnrExchangeRate::class);
    }

    /**
     * Most recent published rate for the currency on or before the given date.
     * The `<=` fallback covers weekends/holidays (BNR does not publish then),
     * returning the last official rate, which is the legally correct one.
     */
    public function findRateValidAt(string $currency, \DateTimeInterface $date): ?BnrExchangeRate
    {
        return $this->createQueryBuilder('r')
            ->where('r.currency = :currency')
            ->andWhere('r.rateDate <= :date')
            ->setParameter('currency', $currency)
            ->setParameter('date', $date)
            ->orderBy('r.rateDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
