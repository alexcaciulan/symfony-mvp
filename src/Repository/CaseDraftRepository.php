<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CaseDraft;
use App\Entity\Document;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CaseDraft> */
class CaseDraftRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CaseDraft::class);
    }

    /** @return list<CaseDraft> most recently worked on first */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.lastActivityAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByIdAndUser(int $id, User $user): ?CaseDraft
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    /** @return list<CaseDraft> drafts nobody touched since the cutoff */
    public function findInactiveSince(\DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.lastActivityAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes the draft and keeps the loaded documents consistent with what the
     * database does on delete (their link is set to null), so a later flush in
     * the same request does not meet a reference to a row that is gone.
     */
    public function removeDraft(CaseDraft $draft): void
    {
        $em = $this->getEntityManager();
        foreach ($em->getUnitOfWork()->getIdentityMap()[Document::class] ?? [] as $document) {
            if ($document instanceof Document && $document->getDraft() === $draft) {
                $document->setDraft(null);
            }
        }
        $em->remove($draft);
        $em->flush();
    }

    /**
     * Writes the payload and the list columns only when the row still carries
     * the version the caller loaded, and bumps the version with it. Returns
     * false when another request wrote in between, so the caller can reload,
     * merge and try again. The entity in memory is refreshed on success.
     */
    public function updateIfVersion(CaseDraft $draft, int $expectedVersion, string $payload, int $payloadVersion, \DateTimeImmutable $now): bool
    {
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE case_draft SET payload = :payload, payload_version = :payloadVersion, version = version + 1,'
            . ' step_reached = :step, documents_count = :documents, creditor_name = :creditor,'
            . ' updated_at = :now, last_activity_at = :now'
            . ' WHERE id = :id AND version = :version',
            [
                'payload' => $payload,
                'payloadVersion' => $payloadVersion,
                'step' => $draft->getStepReached(),
                'documents' => $draft->getDocumentsCount(),
                'creditor' => $draft->getCreditorName(),
                'now' => $now->format('Y-m-d H:i:s'),
                'id' => $draft->getId(),
                'version' => $expectedVersion,
            ],
            ['payload' => ParameterType::BINARY],
        );

        if ($affected !== 1) {
            return false;
        }

        $this->getEntityManager()->refresh($draft);

        return true;
    }
}
