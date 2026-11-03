<?php

declare(strict_types=1);

namespace App\Service\Wizard;

use App\Entity\CaseDraft;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\ExtractionStatus;
use App\Repository\CaseDraftRepository;
use App\Repository\DocumentRepository;
use App\Service\AuditLogService;
use App\Service\Document\DocumentUploadService;

/**
 * Removes an unfinished run together with the documents uploaded for it:
 * the rows, the files on disk and the draft, with one audit entry naming
 * why it went.
 */
final class CaseDraftDiscarder
{
    public const REASON_DISCARDED = 'draft_discarded';
    public const REASON_EXPIRED = 'draft_expired';

    public function __construct(
        private readonly CaseDraftRepository $drafts,
        private readonly DocumentRepository $documents,
        private readonly DocumentUploadService $uploads,
        private readonly CaseDraftPayloadCodec $codec,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @param ?User $actor who asked for it; null for the sweeper
     * @return int how many documents were deleted with the draft
     */
    public function discard(CaseDraft $draft, ?User $actor, string $reason): int
    {
        $documents = $this->documentsOf($draft);
        $deleted = 0;
        foreach ($documents as $document) {
            // A document under extraction is written by the worker; it is left
            // for the orphan sweep, its draft link is cleared with the draft.
            if (in_array($document->getExtractionStatus(), [ExtractionStatus::PROCESSING, ExtractionStatus::PENDING_RETRY], true)) {
                continue;
            }
            $this->uploads->delete($document, $actor);
            ++$deleted;
        }

        $this->auditLog->log(
            action: $reason,
            entityType: CaseDraft::class,
            entityId: (string) $draft->getId(),
            oldData: CaseDraftWizardStateStore::describe($draft) + [
                'lastActivityAt' => $draft->getLastActivityAt()->format(\DateTimeInterface::ATOM),
                'documentsDeleted' => $deleted,
            ],
            category: AuditLogService::CATEGORY_CASE_DRAFT,
            actor: $actor,
        );
        $this->drafts->removeDraft($draft);

        return $deleted;
    }

    /**
     * The draft's pending documents: the ones linked to it, plus the ones its
     * list names that are the owner's and belong to no case yet.
     *
     * @return list<Document>
     */
    public function documentsOf(CaseDraft $draft): array
    {
        $byId = [];
        foreach ($this->documents->findByDraft($draft) as $document) {
            $byId[$document->getId()] = $document;
        }

        $raw = $this->codec->decode($draft->getPayload(), $draft->getPayloadVersion());
        $listed = $raw !== null ? WizardBag::fromArray($raw)->documentIds : [];
        $listed = array_values(array_filter($listed, static fn (int $id): bool => !isset($byId[$id])));
        if ($listed !== []) {
            foreach ($this->documents->findBy(['id' => $listed]) as $document) {
                if ($document->getUploadedBy()->getId() === $draft->getUser()->getId() && $document->getLegalCase() === null) {
                    $byId[$document->getId()] = $document;
                }
            }
        }
        ksort($byId);

        return array_values($byId);
    }
}
