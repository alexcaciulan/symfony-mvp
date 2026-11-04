<?php

declare(strict_types=1);

namespace App\Service\Case;

use App\Entity\LegalCase;
use App\Entity\User;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The lawyer's internal notes on a case: free text that reaches no act and no
 * calculation, editable in every status. The audit trail records only the
 * length and a digest of the text before and after, never the text itself: the
 * notes are professional-secrecy material that must not leak through audit
 * views or exports. The last save wins, which is acceptable for notes.
 */
final class CaseNotesService
{
    public const ACTION = 'case_notes_edited';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogService $auditLogService,
    ) {}

    /** True when the notes changed and were saved; an unchanged text writes nothing. */
    public function update(LegalCase $case, ?string $notes, User $actor): bool
    {
        $before = $case->getNotes();
        $after = LegalCaseClaimFieldsMapper::trimmedOrNull($notes);
        if ($after === $before) {
            return false;
        }

        $case->setNotes($after);
        $this->auditLogService->log(
            action: self::ACTION,
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            oldData: self::fingerprint($before),
            newData: self::fingerprint($after) + ['caseNumber' => $case->getCaseNumber()],
            category: AuditLogService::CATEGORY_CASE_NOTES,
            actor: $actor,
        );
        $this->em->flush();

        return true;
    }

    /** @return array{notesLength: int, notesSha256: ?string} */
    private static function fingerprint(?string $notes): array
    {
        return [
            'notesLength' => $notes === null ? 0 : mb_strlen($notes),
            'notesSha256' => $notes === null ? null : hash('sha256', $notes),
        ];
    }
}
