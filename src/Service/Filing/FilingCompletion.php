<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\FilingChannel;
use App\Enum\FilingPackageStatus;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;

/**
 * What follows the SMTP acceptance of the petition email (the first part, with
 * the power of attorney). The case moves to CERERE_DEPUSA so it cannot be sent
 * twice and the portal discovery picks it up, but the filing date stays empty:
 * a mail server accepting a message is not the court registering a petition.
 */
final class FilingCompletion
{
    public function __construct(
        private readonly CaseWorkflowService $workflow,
        private readonly FilingTransitionPermit $permit,
        private readonly FilingNotifier $notifier,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Runs in the transaction that records the acceptance.
     *
     * @param ?User $actor who established the acceptance: the requesting lawyer, or
     *                     the lawyer declaring the copy received; never an admin
     */
    public function onPetitionAccepted(FilingSubmission $submission, ?User $actor = null): void
    {
        $case = $submission->getLegalCase();
        $actor ??= $submission->getInitiatedBy();
        $messageId = (string) $submission->getPart(1)?->getMessageId();

        if ($case->getStatus() !== CaseStatus::CERERE_GENERATA) {
            // The petition left but the case went on without it: the submission keeps
            // holding the case until the lawyer closes the rest (or an admin does).
            $submission->markNeedsReview('filing.status.review.case_moved_on');
            $this->audit($case, $submission, $actor, 'filing_petition_accepted_case_moved_on');
            $this->notifier->needsReview($submission, 'filing.status.review.case_moved_on');

            return;
        }

        $case->setFilingChannel(FilingChannel::EMAIL);
        $case->setFilingReference($messageId);
        $submission->getPackage()->setStatus(FilingPackageStatus::SENT);

        $this->permit->grant((int) $submission->getId());
        try {
            $this->workflow->apply($case, CaseTransition::DEPUNE_CERERE->value, [
                'actor' => $actor,
                'filing_submission_id' => $submission->getId(),
            ]);
        } finally {
            $this->permit->revoke();
        }

        $this->audit($case, $submission, $actor, 'filing_petition_accepted');
        $this->notifier->petitionSent($submission);
    }

    private function audit(LegalCase $case, FilingSubmission $submission, User $actor, string $action): void
    {
        $this->auditLogService->log(
            action: $action,
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            newData: [
                'submissionId' => $submission->getId(),
                'messageId' => $submission->getPart(1)?->getMessageId(),
                'sentAt' => $submission->getPart(1)?->getSentAt()?->format(\DateTimeInterface::ATOM),
                'filedAt' => $case->getFiledAt()?->format('Y-m-d'),
            ],
            category: AuditLogService::CATEGORY_FILING_SUBMISSION,
            actor: $actor,
        );
    }
}
