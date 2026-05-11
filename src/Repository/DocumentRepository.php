<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\LegalCase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Document> */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * Bulk-attach pre-uploaded documents to a freshly persisted LegalCase.
     *
     * Used by the wizard step 4 submit: documents uploaded in step 0 are
     * persisted with `legalCase = NULL` (fallback path `_pending/{userId}/...`),
     * and only at submit are they linked to the case. Single UPDATE keeps the
     * transaction short — no per-row hydrate.
     *
     * @param int[] $documentIds
     */
    public function attachToCase(array $documentIds, LegalCase $case): int
    {
        if ($documentIds === []) {
            return 0;
        }

        return $this->createQueryBuilder('d')
            ->update()
            ->set('d.legalCase', ':case')
            ->where('d.id IN (:ids)')
            ->setParameter('case', $case)
            ->setParameter('ids', $documentIds)
            ->getQuery()
            ->execute();
    }
}
