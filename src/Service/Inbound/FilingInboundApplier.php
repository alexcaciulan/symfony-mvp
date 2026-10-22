<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\FilingEmailPart;
use App\Entity\FilingSubmission;
use App\Entity\InboundMail;
use App\Entity\LegalCase;
use App\Enum\CaseStatus;
use App\Enum\CaseTransition;
use App\Enum\FilingAttemptOutcome;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingEvidenceStatus;
use App\Enum\FilingPackageStatus;
use App\Enum\FilingPartStatus;
use App\Enum\InboundClassification;
use App\Enum\InboundMatchMethod;
use App\EventSubscriber\FilingWorkflowInterlockSubscriber;
use App\Repository\FilingSubmissionRepository;
use App\Service\AuditLogService;
use App\Service\Case\CaseWorkflowService;
use App\Service\Court\CourtEmailActionRefusedException;
use App\Service\Court\CourtEmailAddressService;
use App\Service\Deadline\DeadlineService;
use App\Service\Filing\FilingLockQuery;
use App\Service\Filing\FilingNotifier;
use App\Service\Filing\FilingPartOutcomeApplier;
use App\Service\Filing\FilingTransitionPermit;
use App\Service\Filing\SmtpFailure;
use App\Service\Filing\SmtpFailureClassifier;
use App\Service\Filing\SmtpFailureKind;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a classified and matched message changes. Runs inside the caller's
 * transaction and locks the case, then the submission, then its parts, in the
 * same order as the filing services.
 *
 * Only a CERTAIN match acts on a submission. Anything less goes to review and
 * changes no status: a wrong attribution could undo a filing or show one client's
 * mail to another lawyer. A message never sets the filing date or the court case
 * number, and never counts as a registration: a receipt only records that the
 * court mailbox answered, a read receipt only that a client displayed it.
 */
final class FilingInboundApplier
{
    public const REVIEW_PROBABLE = 'probable_match';
    public const REVIEW_UNMATCHED = 'unmatched';
    public const REVIEW_LATE_BOUNCE = 'late_bounce_case_advanced';
    public const REVIEW_UNKNOWN_RECIPIENT = 'bounce_unknown_recipient';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilingSubmissionRepository $submissions,
        private readonly FilingPartOutcomeApplier $outcomes,
        private readonly FilingNotifier $notifier,
        private readonly InboundForwarder $forwarder,
        private readonly CourtEmailAddressService $addresses,
        private readonly CaseWorkflowService $workflow,
        private readonly FilingTransitionPermit $permit,
        private readonly DeadlineService $deadlines,
        private readonly FilingLockQuery $filingLock,
        private readonly SmtpFailureClassifier $smtpClassifier,
        private readonly AuditLogService $auditLogService,
        private readonly string $courtFilingFrom,
    ) {}

    public function apply(InboundMail $mail, ParsedInboundMail $parsed): void
    {
        $class = $mail->getClassification() ?? InboundClassification::UNKNOWN;
        $submission = $mail->getMatchedSubmission();

        if ($submission === null) {
            if ($class !== InboundClassification::SPAM) {
                $mail->markForReview(self::REVIEW_UNMATCHED);
            }

            return;
        }
        if ($class === InboundClassification::SPAM) {
            return;
        }
        if (!$mail->isCertain()) {
            $this->probable($mail, $submission, $parsed, $class);

            return;
        }

        $this->lock($submission);
        match ($class) {
            InboundClassification::RECEIPT => $this->receipt($mail, $submission, $parsed),
            InboundClassification::READ_RECEIPT => $this->readReceipt($mail, $submission),
            InboundClassification::BOUNCE_PERMANENT, InboundClassification::BOUNCE_SIZE => $this->permanentBounce($mail, $submission),
            InboundClassification::BOUNCE_TEMPORARY, InboundClassification::DELAYED => $this->delayed($mail, $submission),
            InboundClassification::HUMAN_REPLY, InboundClassification::AUTO_REPLY_OTHER => $this->courtMessage($mail, $submission, $parsed),
            default => null,
        };
    }

    /**
     * Not proven to concern this submission: nothing is applied. A message that
     * names one of our unguessable identifiers (a reply on our Message-ID, a report
     * quoting it) can only come from someone who received that email, so the lawyer
     * is told and a court answer is forwarded; a guess from the sender's domain
     * stays in the admin queue.
     */
    private function probable(InboundMail $mail, FilingSubmission $submission, ParsedInboundMail $parsed, InboundClassification $class): void
    {
        $mail->markForReview(self::REVIEW_PROBABLE);
        if ($mail->getMatchMethod() === InboundMatchMethod::HEURISTIC) {
            return;
        }
        if (in_array($class, [InboundClassification::HUMAN_REPLY, InboundClassification::AUTO_REPLY_OTHER, InboundClassification::RECEIPT], true)
            && !$this->isOwnOrLoop($mail, $submission, $parsed)) {
            $this->forwarder->forward($mail, $submission, $parsed);
        }
        if ($class !== InboundClassification::READ_RECEIPT) {
            $this->notifier->inboundReview($submission, $mail);
        }
        $this->audit($submission, 'inbound_mail_review', ['inboundMailId' => $mail->getId(), 'classification' => $class->value, 'method' => $mail->getMatchMethod()?->value]);
    }

    /** The court mailbox acknowledged the message: evidence of receipt only, the date is a proposal. */
    private function receipt(InboundMail $mail, FilingSubmission $submission, ParsedInboundMail $parsed): void
    {
        if ($submission->getSentAt() === null) {
            $mail->markForReview('receipt_before_sent');

            return;
        }
        $before = $submission->getEvidenceStatus();
        $submission->markReceipt($mail->getReceivedAt(), $mail, FilingEvidenceStatus::RECEIPT_RECEIVED);
        $this->audit($submission, 'filing_receipt_received', [
            'inboundMailId' => $mail->getId(),
            'receivedAt' => $mail->getReceivedAt()->format(\DateTimeInterface::ATOM),
            'previousEvidence' => $before->value,
            'authVerdict' => $mail->getAuthVerdict()?->value,
        ]);
        $this->notifier->receiptReceived($submission, $mail);
        if (!$this->isOwnOrLoop($mail, $submission, $parsed)) {
            $this->forwarder->forward($mail, $submission, $parsed);
        }
    }

    private function readReceipt(InboundMail $mail, FilingSubmission $submission): void
    {
        $submission->markReadReceipt($mail->getReceivedAt(), $mail);
        $this->audit($submission, 'filing_read_receipt_received', ['inboundMailId' => $mail->getId()]);
    }

    private function permanentBounce(InboundMail $mail, FilingSubmission $submission): void
    {
        $part = $mail->getMatchedPart();
        $final = mb_strtolower(trim((string) $mail->getDsnFinalRecipient()));
        if ($part === null) {
            $mail->markForReview(self::REVIEW_UNKNOWN_RECIPIENT);

            return;
        }
        if ($final !== '' && $final === mb_strtolower($submission->getCcAddress())) {
            $this->audit($submission, 'filing_cc_bounced', ['inboundMailId' => $mail->getId(), 'sequence' => $part->getSequence(), 'status' => $mail->getDsnStatus()]);
            $this->notifier->ccBounced($submission, $mail);

            return;
        }
        if ($final === '' || $final !== mb_strtolower($submission->getToAddress())) {
            $mail->markForReview(self::REVIEW_UNKNOWN_RECIPIENT);
            $this->notifier->inboundReview($submission, $mail);

            return;
        }

        $smtp = $this->smtpClassifier->classifyStatus(
            null,
            $mail->getDsnStatus(),
            'dsn',
            true,
            'Delivery report: ' . self::withoutAddresses((string) ($mail->getDsnDiagnostic() ?? $mail->getDsnStatus() ?? '-')),
        );
        if ($smtp->kind !== SmtpFailureKind::SIZE && $smtp->kind !== SmtpFailureKind::PERMANENT) {
            // A final report on a full mailbox (5.2.2): undelivered, the address stays in use.
            $smtp = new SmtpFailure(SmtpFailureKind::PERMANENT, $smtp->smtpCode, $smtp->enhancedStatus, $smtp->stage, false, $smtp->safeMessage);
        }

        if ($part->isFirst()) {
            $this->undeliverPetition($mail, $submission, $part, $smtp);

            return;
        }
        if (FilingPartOutcomeApplier::hasAsyncFailure($part)) {
            return;
        }
        $this->outcomes->asyncFailed($part, $smtp, $mail);
    }

    /**
     * The petition email came back for good after the server accepted it. On a case
     * still at CERERE_DEPUSA with nothing showing the petition arrived, the filing is
     * undone (T9): the case returns to a generated request, the package can be sent
     * again, and the platform's own closing of the six-month term is reopened in the
     * application. Otherwise nothing is undone and the lawyer reviews it.
     */
    private function undeliverPetition(InboundMail $mail, FilingSubmission $submission, FilingEmailPart $part, SmtpFailure $smtp): void
    {
        if ($submission->getDeliveryStatus() === FilingDeliveryStatus::UNDELIVERED || FilingPartOutcomeApplier::hasAsyncFailure($part)
            || $part->getStatus() !== FilingPartStatus::ACCEPTED) {
            return;
        }
        $case = $submission->getLegalCase();
        $failure = $smtp->kind === SmtpFailureKind::SIZE ? FilingPartStatus::FAILED_SIZE : FilingPartStatus::FAILED_PERMANENT;
        $this->outcomes->recordAsyncAttempt($part, $failure === FilingPartStatus::FAILED_SIZE ? FilingAttemptOutcome::SIZE : FilingAttemptOutcome::PERMANENT, $smtp);
        $part->recordAsyncFailure($failure, $smtp->enhancedStatus, $smtp->safeMessage);
        foreach ($submission->getParts() as $other) {
            if ($other->getStatus()->isCancellable()) {
                $other->setStatus(FilingPartStatus::CANCELLED);
            }
        }

        $latest = $this->submissions->findLatestForCase($case);
        if ($case->getStatus() !== CaseStatus::CERERE_DEPUSA || !FilingWorkflowInterlockSubscriber::canUndoFiling($case)
            || $latest?->getId() !== $submission->getId()) {
            $this->lateReview($mail, $submission);

            return;
        }

        $previous = $submission->getDeliveryStatus();
        $submission->markUndelivered();
        $addressInvalidated = false;
        $address = $submission->getCourtEmailAddress();
        if ($smtp->invalidatesCourtAddress && $address !== null) {
            $details = [
                'enhancedStatus' => $smtp->enhancedStatus,
                'source' => 'delivery_report',
                'recipientOverridden' => $submission->isRecipientOverridden(),
            ];
            try {
                $this->addresses->markInvalid($address, 'async_bounce', null, $submission, $details, $mail);
                $addressInvalidated = true;
            } catch (CourtEmailActionRefusedException) {
                $this->audit($submission, 'filing_court_address_already_invalid', ['courtEmailAddressId' => $address->getId()] + $details);
            }
        }

        $case->setFilingChannel(null);
        $case->setFilingReference(null);
        if ($submission->getPackage()->getStatus() === FilingPackageStatus::SENT) {
            $submission->getPackage()->setStatus(FilingPackageStatus::SIGNED);
        }

        $this->permit->grant((int) $submission->getId());
        try {
            $this->workflow->apply($case, CaseTransition::ANULEAZA_DEPUNERE_NELIVRATA->value, [
                'filing_submission_id' => $submission->getId(),
                'inbound_mail_id' => $mail->getId(),
                'reason' => 'delivery_report:' . ($smtp->enhancedStatus ?? '-'),
            ]);
        } finally {
            $this->permit->revoke();
        }
        $reopened = $this->deadlines->reopenFilingDeadline($case, 'filing_undelivered:' . ($smtp->enhancedStatus ?? '-'));
        $this->filingLock->forget($case);

        $this->audit($submission, 'filing_submission_undelivered', [
            'inboundMailId' => $mail->getId(),
            'previousStatus' => $previous->value,
            'enhancedStatus' => $smtp->enhancedStatus,
            'partStatus' => $failure->value,
            'addressInvalidated' => $addressInvalidated,
            'deadlineReopened' => $reopened,
        ]);
        $this->notifier->bouncedPermanent($submission, $part, $mail, $addressInvalidated, $reopened);
    }

    /** The case moved on (or another submission holds it): nothing is undone, the lawyer checks. */
    private function lateReview(InboundMail $mail, FilingSubmission $submission): void
    {
        $case = $submission->getLegalCase();
        $mail->markForReview(self::REVIEW_LATE_BOUNCE);
        if (!$submission->isActive() && $submission->getDeliveryStatus()->isTerminal() && !$this->filingLock->isLockedNow($case)) {
            $submission->openLateReview(FilingPartOutcomeApplier::REVIEW_LATE_BOUNCE);
            $this->filingLock->forget($case);
        }
        $this->audit($submission, 'filing_late_bounce_review', [
            'inboundMailId' => $mail->getId(),
            'caseStatus' => $case->getStatus()->value,
            'hasCourtCaseNumber' => $case->getCourtCaseNumber() !== null,
            'hasFiledAt' => $case->getFiledAt() !== null,
            'locked' => $submission->isActive(),
        ]);
        $this->notifier->lateBounceReview($submission, $mail);
    }

    /** Temporary or delayed: recorded and told, never resent on the strength of a report. */
    private function delayed(InboundMail $mail, FilingSubmission $submission): void
    {
        $part = $mail->getMatchedPart();
        if ($part === null) {
            return;
        }
        $smtp = $this->smtpClassifier->classifyStatus(null, $mail->getDsnStatus(), 'dsn', false, sprintf('Delivery report %s (%s)', $mail->getDsnStatus() ?? '-', $mail->getDsnAction() ?? '-'));
        $this->outcomes->recordAsyncAttempt($part, FilingAttemptOutcome::TEMPORARY, $smtp);
        $this->audit($submission, 'filing_delivery_delayed', [
            'inboundMailId' => $mail->getId(),
            'sequence' => $part->getSequence(),
            'status' => $mail->getDsnStatus(),
            'action' => $mail->getDsnAction(),
        ]);
        $final = mb_strtolower(trim((string) $mail->getDsnFinalRecipient()));
        if ($final !== '' && $final === mb_strtolower($submission->getCcAddress())) {
            return;
        }
        $this->notifier->deliveryDelayed($submission, $part, $mail);
    }

    /**
     * An answer from the court side. The platform address is not a procedural
     * address, so it is forwarded at once and the notice says so; nothing on the
     * case changes and no deadline is derived from it.
     */
    private function courtMessage(InboundMail $mail, FilingSubmission $submission, ParsedInboundMail $parsed): void
    {
        if ($this->isOwnOrLoop($mail, $submission, $parsed)) {
            $this->audit($submission, 'inbound_mail_ignored_own', ['inboundMailId' => $mail->getId()]);

            return;
        }
        $forwarded = $this->forwarder->forward($mail, $submission, $parsed);
        $this->audit($submission, 'filing_court_message', ['inboundMailId' => $mail->getId(), 'classification' => $mail->getClassification()?->value, 'forwarded' => $forwarded]);
        $this->notifier->courtMessage($submission, $mail, $forwarded || $mail->getForwardedAt() !== null);
    }

    /** The lawyer's own message, ours, or an answer to one of our forwards: never forwarded again. */
    private function isOwnOrLoop(InboundMail $mail, FilingSubmission $submission, ParsedInboundMail $parsed): bool
    {
        $from = mb_strtolower((string) $mail->getFromAddress());
        if ($parsed->marker('x-lexrecovery-forward') !== null) {
            return true;
        }

        return $from !== '' && in_array($from, [
            mb_strtolower((string) $submission->getLegalCase()->getUser()->getEmail()),
            mb_strtolower($submission->getCcAddress()),
            mb_strtolower(trim($this->courtFilingFrom)),
        ], true);
    }

    private function lock(FilingSubmission $submission): void
    {
        $case = $submission->getLegalCase();
        $this->em->lock($case, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($case);
        $this->em->lock($submission, LockMode::PESSIMISTIC_WRITE);
        foreach ($submission->getParts() as $part) {
            $this->em->lock($part, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($part);
        }
        $this->em->refresh($submission);
    }

    private static function withoutAddresses(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/<?[^\s<>@"]+@[^\s<>"]+>?/', '[address]', $text)), 0, 300);
    }

    /** @param array<string, mixed> $data */
    private function audit(FilingSubmission $submission, string $action, array $data): void
    {
        $this->auditLogService->log(
            action: $action,
            entityType: LegalCase::class,
            entityId: (string) $submission->getLegalCase()->getId(),
            newData: ['submissionId' => $submission->getId(), ...$data],
            category: AuditLogService::CATEGORY_FILING_SUBMISSION,
            actor: $submission->getInitiatedBy(),
        );
    }
}
