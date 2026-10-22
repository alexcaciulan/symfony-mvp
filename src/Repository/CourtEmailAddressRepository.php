<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Court;
use App\Entity\CourtEmailAddress;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CourtEmailAddress> */
class CourtEmailAddressRepository extends ServiceEntityRepository
{
    private const FILING_PURPOSES = [
        CourtEmailPurpose::REGISTRY->value,
        CourtEmailPurpose::SECTION_CIVIL->value,
        CourtEmailPurpose::SECTION_PROFESSIONALS->value,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CourtEmailAddress::class);
    }

    /**
     * Confirmed addresses first, then the proposals, then the rejected and invalid ones.
     *
     * @return list<CourtEmailAddress>
     */
    public function findForCourt(Court $court, bool $includePersonal = false): array
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect("CASE WHEN a.status = 'VALIDATED' THEN 0 WHEN a.status = 'PROPOSED' THEN 1 ELSE 2 END AS HIDDEN statusOrder")
            ->andWhere('a.court = :court')
            ->setParameter('court', $court)
            ->orderBy('statusOrder', 'ASC')
            ->addOrderBy('a.purpose', 'ASC')
            ->addOrderBy('a.email', 'ASC');
        if (!$includePersonal) {
            $qb->andWhere('a.purpose <> :personal')->setParameter('personal', CourtEmailPurpose::PERSONAL);
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneForCourtByEmail(Court $court, string $email): ?CourtEmailAddress
    {
        return $this->findOneBy(['court' => $court, 'email' => CourtEmailAddress::normalizeEmail($email)]);
    }

    /** @return list<CourtEmailAddress> */
    public function findDefaultsForCourtAndPurpose(Court $court, CourtEmailPurpose $purpose): array
    {
        return $this->findBy([
            'court' => $court,
            'purpose' => $purpose,
            'status' => CourtEmailStatus::VALIDATED,
            'filingDefault' => true,
        ]);
    }

    public function findForCourtById(Court $court, int $id): ?CourtEmailAddress
    {
        return $this->findOneBy(['court' => $court, 'id' => $id]);
    }

    /**
     * Active courts with no address a filing could go to today: no confirmed,
     * filing-purpose address that is still within its confirmation window.
     */
    public function countActiveCourtsWithoutValidatedAddress(\DateTimeImmutable $now): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*) FROM court c
            WHERE c.active = 1
              AND NOT EXISTS (
                SELECT 1 FROM court_email_address a
                WHERE a.court_id = c.id
                  AND a.status = 'VALIDATED'
                  AND a.purpose IN (:purposes)
                  AND a.missing_since IS NULL
                  AND a.validated_at >= :threshold
              )
            SQL;

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            $sql,
            ['purposes' => self::FILING_PURPOSES, 'threshold' => $this->threshold($now)],
            ['purposes' => ArrayParameterType::STRING],
        );
    }

    public function countProposed(): int
    {
        return $this->count(['status' => CourtEmailStatus::PROPOSED]);
    }

    public function countExpiredValidations(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.status = :validated')
            ->andWhere('a.validatedAt < :threshold OR a.missingSince IS NOT NULL')
            ->setParameter('validated', CourtEmailStatus::VALIDATED)
            ->setParameter('threshold', $now->modify('-' . CourtEmailAddress::VALIDATION_TTL_DAYS . ' days'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function threshold(\DateTimeImmutable $now): string
    {
        return $now->modify('-' . CourtEmailAddress::VALIDATION_TTL_DAYS . ' days')->format('Y-m-d H:i:s');
    }
}
