<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InboundPollHeartbeat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InboundPollHeartbeat>
 */
class InboundPollHeartbeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboundPollHeartbeat::class);
    }

    /** The single row, created on first use. */
    public function current(): InboundPollHeartbeat
    {
        $row = $this->findOneBy([], ['id' => 'ASC']);
        if ($row === null) {
            $row = new InboundPollHeartbeat();
            $this->getEntityManager()->persist($row);
        }

        return $row;
    }
}
