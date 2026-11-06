<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\Document;
use App\Entity\FilingPackage;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\FilingPackageStatus;
use App\Service\Case\CaseNextStepFacts;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Loads in bulk the facts a row suggestion needs, at most four queries for any number
 * of rows. Only the statuses that suggest anything are asked about. The portal facts
 * stay false: they matter on later stages than the ones a row suggests for.
 */
final class CaseNextStepFactsProvider
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * @param list<LegalCase> $cases
     *
     * @return array<int, CaseNextStepFacts> by case id
     */
    public function forCases(array $cases): array
    {
        $this->warmPortalMatches($cases);

        $proofs = $this->casesWithProof($this->idsIn($cases, CaseStatus::SOMATIE_TRIMISA));
        $filings = $this->casesWithEmailFiling($this->idsIn($cases, CaseStatus::CERERE_GENERATA));

        $facts = [];
        foreach ($cases as $case) {
            $id = (int) $case->getId();
            $facts[$id] = new CaseNextStepFacts(
                hasCommunicationProof: isset($proofs[$id]),
                filingPanelReady: $case->getFullPaymentDate() === null,
                hasEmailFiling: isset($filings[$id]),
            );
        }

        return $facts;
    }

    /**
     * The next-step rule reads the proposed portal number off the case, from a lazy
     * collection, so each row would otherwise fire its own SELECT. The result is discarded
     * on purpose: what the rule needs is the collections hydrated in the identity map.
     *
     * @param list<LegalCase> $cases
     */
    private function warmPortalMatches(array $cases): void
    {
        $ids = $this->idsIn($cases, CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA, CaseStatus::CERERE_GENERATA);
        if ($ids === []) {
            return;
        }

        $this->em
            ->createQuery(sprintf('SELECT c, matches FROM %s c LEFT JOIN c.portalCaseMatches matches WHERE c.id IN (:ids)', LegalCase::class))
            ->setParameter('ids', $ids)
            ->getResult();
    }

    /**
     * @param list<LegalCase> $cases
     *
     * @return list<int>
     */
    private function idsIn(array $cases, CaseStatus ...$statuses): array
    {
        $ids = [];
        foreach ($cases as $case) {
            if (\in_array($case->getStatus(), $statuses, true)) {
                $ids[] = (int) $case->getId();
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $caseIds
     *
     * @return array<int, true>
     */
    private function casesWithProof(array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }

        return $this->idSet($this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(d.legalCase) AS caseId')
            ->from(Document::class, 'd')
            ->where('d.legalCase IN (:cases)')
            ->andWhere('d.documentType = :type')
            ->setParameter('cases', $caseIds)
            ->setParameter('type', DocumentType::DOVADA_COMUNICARE)
            ->getQuery()
            ->getArrayResult());
    }

    /**
     * @param list<int> $caseIds
     *
     * @return array<int, true>
     */
    private function casesWithEmailFiling(array $caseIds): array
    {
        if ($caseIds === []) {
            return [];
        }

        $packages = $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(p.legalCase) AS caseId')
            ->from(FilingPackage::class, 'p')
            ->where('p.legalCase IN (:cases)')
            ->andWhere('p.status != :superseded')
            ->setParameter('cases', $caseIds)
            ->setParameter('superseded', FilingPackageStatus::SUPERSEDED)
            ->getQuery()
            ->getArrayResult();

        $submissions = $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(s.legalCase) AS caseId')
            ->from(FilingSubmission::class, 's')
            ->where('s.legalCase IN (:cases)')
            ->setParameter('cases', $caseIds)
            ->getQuery()
            ->getArrayResult();

        return $this->idSet([...$packages, ...$submissions]);
    }

    /**
     * @param list<array{caseId: int|string}> $rows
     *
     * @return array<int, true>
     */
    private function idSet(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['caseId']] = true;
        }

        return $ids;
    }
}
