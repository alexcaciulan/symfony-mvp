<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Repository\NotificationRepository;
use App\Service\AuditLogService;
use App\Util\PiiMasker;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes a case from the lawyer's lists while nothing has left the platform
 * (AMIABIL). The row stays, marked with `deletedAt`, together with its documents,
 * positions and deadlines: nothing is purged, and an administrator can clear the
 * mark. No refund either: the subscription slot is consumed when the summons goes
 * out, which never happened here.
 *
 * The status is read again under a row lock: a summons generated from another tab
 * in the meantime would otherwise be deleted with the case.
 */
final class CaseDeletionService
{
    public const ACTION = 'case_deleted';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notifications,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function canDelete(LegalCase $case): bool
    {
        return !$case->isDeleted() && $case->getStatus() === CaseStatus::AMIABIL;
    }

    /**
     * @param ?string $reason free text the lawyer typed, kept in the audit entry only
     *
     * @throws \DomainException when the case is past the amiable stage or already deleted
     */
    public function delete(LegalCase $case, User $actor, ?string $reason): void
    {
        // Cheap refusal before the transaction: a refusal inside it closes the
        // entity manager, which the caller still needs to answer the request.
        if (!$this->canDelete($case)) {
            throw new \DomainException('Only an amiable case still in the lists can be deleted; status ' . $case->getStatus()->value);
        }

        $this->em->wrapInTransaction(function () use ($case, $actor, $reason): void {
            $this->em->lock($case, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($case);

            if (!$this->canDelete($case)) {
                throw new \DomainException('The case changed while the deletion was confirmed; status ' . $case->getStatus()->value);
            }

            $snapshot = $this->snapshot($case, $reason);

            $case->markAsDeleted();
            $this->em->flush();

            $this->notifications->markReadByLegalCase($case);

            $this->auditLogService->log(
                action: self::ACTION,
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: $snapshot,
                category: AuditLogService::CATEGORY_CASE_DELETED,
                actor: $actor,
            );
            $this->em->flush();
        });
    }

    /**
     * What the case held when it left the lists, so the audit entry can be read
     * without the row: the parties, the sums, the documents and the summons date.
     *
     * @return array<string, mixed>
     */
    private function snapshot(LegalCase $case, ?string $reason): array
    {
        $creditor = $case->getCreditor();
        $documents = $case->getDocuments();

        return PiiMasker::maskCnpInArray([
            'caseNumber' => $case->getCaseNumber(),
            'status' => $case->getStatus()->value,
            'creditor' => $creditor === null ? null : [
                'name' => $creditor->getName(),
                'cui' => $creditor->getCui(),
            ],
            'debtors' => array_map(
                static fn (LegalCaseDebtor $link): array => [
                    'name' => $link->getName(),
                    'cui' => $link->getCui(),
                ],
                $case->getDebtors()->toArray(),
            ),
            'amount' => $case->getAmount(),
            'currency' => $case->getCurrency(),
            'calculatedInterest' => $case->getCalculatedInterest(),
            'claimItemCount' => $case->getClaimItems()->count(),
            'documentCount' => $documents->count(),
            'documents' => array_map(
                static fn (Document $document): array => [
                    'type' => $document->getDocumentType()->value,
                    'filename' => $document->getOriginalFilename(),
                ],
                $documents->toArray(),
            ),
            'paymentNoticeDate' => $case->getPaymentNoticeDate()?->format('Y-m-d'),
            'stampDutyProofUploaded' => $case->hasStampDutyProof(),
            'reason' => $reason,
        ]);
    }
}
