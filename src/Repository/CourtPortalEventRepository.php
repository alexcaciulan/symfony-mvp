<?php

namespace App\Repository;

use App\Entity\CourtPortalEvent;
use App\Entity\LegalCase;
use App\Enum\PortalEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CourtPortalEvent> */
class CourtPortalEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourtPortalEvent::class);
    }

    public function eventExists(
        LegalCase $case,
        PortalEventType $type,
        ?\DateTimeInterface $eventDate,
    ): bool {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.legalCase = :case')
            ->andWhere('e.eventType = :type')
            ->setParameter('case', $case)
            ->setParameter('type', $type);

        if ($eventDate !== null) {
            $qb->andWhere('e.eventDate = :date')
                ->setParameter('date', $eventDate);
        } else {
            $qb->andWhere('e.eventDate IS NULL');
        }

        return ((int) $qb->getQuery()->getSingleScalarResult()) > 0;
    }

    /** @return CourtPortalEvent[] */
    public function findUnnotified(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.notified = false')
            ->orderBy('e.detectedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return CourtPortalEvent[] */
    public function findByLegalCase(LegalCase $case): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.legalCase = :case')
            ->setParameter('case', $case)
            ->orderBy('e.eventDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
