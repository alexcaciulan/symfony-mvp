<?php

declare(strict_types=1);

namespace App\Service\Extraction;

use App\DTO\Extraction\DocumentClassification;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Repository\DocumentRepository;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lets a file the lawyer already had read once keep its reading when they
 * upload it again for another case or another run, instead of paying for a
 * second extraction.
 *
 * The match is on the fingerprint of the bytes and never crosses users: an
 * extraction holds personal data (identifiers, bank accounts) and must not
 * leak from one lawyer's file to another's. The declared type has to agree
 * as well, because the prompt follows the declared type and a reading made
 * as an invoice is not a reading made as a contract; a generic upload
 * accepts whatever the source was read as.
 */
final class ExtractionReuseService
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly AuditLogService $auditLog,
        private readonly DetectedTypePromoter $promoter,
        private readonly EntityManagerInterface $em,
    ) {}

    public function findReusable(User $user, string $contentHash, DocumentType $declared): ?Document
    {
        $qb = $this->documents->createQueryBuilder('d')
            ->andWhere('d.uploadedBy = :user')
            ->andWhere('d.contentHash = :hash')
            ->andWhere('d.extractionStatus = :completed')
            ->andWhere('d.extractedData IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('hash', $contentHash)
            ->setParameter('completed', ExtractionStatus::COMPLETED)
            ->orderBy('d.id', 'DESC')
            ->setMaxResults(1);
        if ($declared !== DocumentType::ALT_DOCUMENT) {
            $qb->andWhere('d.documentType = :declared')->setParameter('declared', $declared);
        }

        $found = $qb->getQuery()->getOneOrNullResult();

        return $found instanceof Document ? $found : null;
    }

    /**
     * Copies the reading, nothing else: not the case, the draft, the power of
     * attorney details, nor any decision taken on the source's rows.
     */
    public function copyInto(Document $source, Document $target): void
    {
        $data = $source->getExtractedData() ?? [];
        $data['sourceDocumentId'] = $target->getId();

        $target->setExtractedData($data);
        $target->setExtractionConfidence($source->getExtractionConfidence());
        $target->setExtractionStrategy($source->getExtractionStrategy());
        $target->setDetectedType($source->getDetectedType());
        $target->setDetectedTypeConfidence($source->getDetectedTypeConfidence());
        $target->setExtractionStatus(ExtractionStatus::COMPLETED);
        $target->setExtractionFailureReason(null);
        $this->promoter->promote($target, DocumentClassification::fromArray($data['classification'] ?? null));

        $this->auditLog->log(
            action: 'extraction_reused',
            entityType: 'Document',
            entityId: (string) $target->getId(),
            newData: [
                'sourceDocumentId' => $source->getId(),
                'contentHash' => $source->getContentHash(),
                'strategy' => $source->getExtractionStrategy(),
                'documentType' => $target->getDocumentType()->value,
            ],
            category: AuditLogService::CATEGORY_AI_EXTRACTION,
        );
        $this->em->flush();
    }
}
