<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClaimItem;
use App\Entity\Document;
use App\Entity\LegalCase;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ClaimItem> */
class ClaimItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClaimItem::class);
    }

    public function findOneByDedupKey(LegalCase $case, string $dedupKey): ?ClaimItem
    {
        return $this->findOneBy(['legalCase' => $case, 'dedupKey' => $dedupKey]);
    }

    /**
     * Ids of the documents some position of the case rests on, as its source or
     * as the title it arises from.
     *
     * @return list<int>
     */
    public function referencedDocumentIds(LegalCase $case): array
    {
        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(i.sourceDocument) AS src, IDENTITY(i.causeDocument) AS cause FROM '.ClaimItem::class.' i WHERE i.legalCase = :case',
        )->setParameter('case', $case)->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            foreach ([$row['src'], $row['cause']] as $id) {
                if ($id !== null) {
                    $ids[(int) $id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /** True when a position of the case rests on the document. */
    public function isReferenced(Document $document): bool
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.sourceDocument = :doc OR i.causeDocument = :doc')
            ->setParameter('doc', $document)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
