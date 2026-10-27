<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Repository\FilingSubmissionRepository;
use App\Service\AuditLogService;
use App\Service\Deadline\WorkingDayResolver;
use App\Service\Filing\FilingNotifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Flags a transmitted petition still without a court receipt after N working days
 * (Romanian calendar with public holidays). A court has no duty to confirm by
 * email, so this proves nothing about the registration and changes no status: the
 * lawyer is only asked to check with the court, and can upload a receipt.
 */
final class ReceiptOverdueChecker
{
    private const TIMEZONE = 'Europe/Bucharest';

    public function __construct(
        private readonly FilingSubmissionRepository $submissions,
        private readonly WorkingDayResolver $workingDays,
        private readonly FilingNotifier $notifier,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
        private readonly int $receiptOverdueWorkingDays,
    ) {}

    /** @return list<FilingSubmission> the submissions flagged now */
    public function run(\DateTimeImmutable $now): array
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $today = $now->setTimezone($tz)->setTime(0, 0);
        $flagged = [];
        foreach ($this->submissions->findReceiptOverdueCandidates() as $submission) {
            if (!$this->isOverdue($submission, $today) || !$submission->markReceiptOverdue()) {
                continue;
            }
            $this->auditLogService->log(
                action: 'filing_receipt_overdue',
                entityType: LegalCase::class,
                entityId: (string) $submission->getLegalCase()->getId(),
                newData: ['submissionId' => $submission->getId(), 'workingDays' => $this->receiptOverdueWorkingDays],
                category: AuditLogService::CATEGORY_FILING_SUBMISSION,
                actor: $submission->getInitiatedBy(),
            );
            $this->notifier->receiptOverdue($submission, $this->receiptOverdueWorkingDays);
            $flagged[] = $submission;
        }
        $this->em->flush();

        return $flagged;
    }

    public function isOverdue(FilingSubmission $submission, \DateTimeImmutable $today): bool
    {
        $sentAt = $submission->getSentAt();
        if ($sentAt === null) {
            return false;
        }
        $sentDay = $sentAt->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);

        return $this->workingDays->addWorkingDays($sentDay, $this->receiptOverdueWorkingDays) < $today;
    }
}
