<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CourtEmailAddress;
use App\Entity\CourtEmailAddressReport;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtEmailReportKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CourtEmailAddressReport>
 */
class CourtEmailAddressReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourtEmailAddressReport::class);
    }

    public function findOpenByLawyer(CourtEmailAddress $address, User $user): ?CourtEmailAddressReport
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.address = :a AND r.reportedBy = :u AND r.kind = :k AND r.resolvedAt IS NULL')
            ->setParameter('a', $address)
            ->setParameter('u', $user)
            ->setParameter('k', CourtEmailReportKind::LAWYER)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** A delivery report on this address still waiting for the admin, raised by a filing of this case. */
    public function hasOpenDeliveryReportForCase(CourtEmailAddress $address, LegalCase $case): bool
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.address = :a AND r.legalCase = :c AND r.kind = :k AND r.resolvedAt IS NULL')
            ->setParameter('a', $address)
            ->setParameter('c', $case)
            ->setParameter('k', CourtEmailReportKind::DELIVERY_REPORT)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * The open reports a lawyer raised, keyed by address id, for the case page.
     *
     * @param list<CourtEmailAddress> $addresses
     *
     * @return array<int, CourtEmailAddressReport>
     */
    public function findOpenByLawyerForAddresses(array $addresses, User $user): array
    {
        if ($addresses === []) {
            return [];
        }
        $out = [];
        foreach ($this->createQueryBuilder('r')
            ->andWhere('r.address IN (:a) AND r.reportedBy = :u AND r.resolvedAt IS NULL')
            ->setParameter('a', $addresses)
            ->setParameter('u', $user)
            ->getQuery()
            ->getResult() as $report) {
            $out[(int) $report->getAddress()->getId()] = $report;
        }

        return $out;
    }

    /** A delivery report raised by this submission, whatever the admin decided since. */
    public function existsForSubmission(FilingSubmission $submission): bool
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.submission = :s AND r.kind = :k')
            ->setParameter('s', $submission)
            ->setParameter('k', CourtEmailReportKind::DELIVERY_REPORT)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** Whether a delivery report raised by this submission still waits for the admin. */
    public function hasOpenForSubmission(FilingSubmission $submission): bool
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.submission = :s AND r.kind = :k AND r.resolvedAt IS NULL')
            ->setParameter('s', $submission)
            ->setParameter('k', CourtEmailReportKind::DELIVERY_REPORT)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** @return list<CourtEmailAddressReport> */
    public function findOpenForAddress(CourtEmailAddress $address): array
    {
        return $this->findBy(['address' => $address, 'resolvedAt' => null], ['id' => 'ASC']);
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.resolvedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
