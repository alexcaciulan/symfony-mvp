<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingEmailPart;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingAttemptSource;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingPartStatus;
use App\Message\SendFilingEmailPartMessage;
use App\Service\AuditLogService;
use App\Service\Court\CourtEmailActionRefusedException;
use App\Service\Court\CourtEmailAddressService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The one place where the result of a court email is recorded and the state of its
 * submission recomputed: the worker, the lawyer's declaration about the copy in CC,
 * the admin override, the resume and the registration on another path all go
 * through it. Every method runs inside the caller's transaction, with the part and
 * the submission already locked and refreshed.
 *
 * The rules: an uncertain part stops the submission for review and keeps the case;
 * all parts accepted ends it as sent; a failure before the petition was accepted
 * ends it and frees the case (the signed package stays reusable); a failure after
 * the petition was accepted leaves it partially sent, holding the case until the
 * lawyer resumes or closes the rest. Nothing here ever sends the petition again.
 */
final class FilingPartOutcomeApplier
{
    public const REVIEW_UNCERTAIN = 'filing.status.review.part_uncertain';
    public const REVIEW_ANNEXES_CANCELLED_PENDING = 'filing.status.review.annexes_cancelled_pending';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilingCompletion $completion,
        private readonly CourtEmailAddressService $addresses,
        private readonly FilingNotifier $notifier,
        private readonly FilingLockQuery $filingLock,
        private readonly FilingResplitService $resplit,
        private readonly AuditLogService $auditLogService,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * The server took the message, or someone with the standing to say so declared it.
     *
     * @param ?User $actor the lawyer or admin who established it; null for the SMTP answer
     */
    public function accepted(FilingEmailPart $part, \DateTimeImmutable $at, FilingAttemptSource $source, ?User $actor = null): void
    {
        if ($part->getStatus() === FilingPartStatus::ACCEPTED) {
            return;
        }
        $submission = $part->getSubmission();
        $previous = $part->getStatus();
        $part->markAccepted($at);
        $this->audit($submission, 'filing_part_accepted', [
            'sequence' => $part->getSequence(),
            'messageId' => $part->getMessageId(),
            'encodedBytes' => $part->getEncodedBytes(),
            'source' => $source->value,
            'previousStatus' => $previous->value,
        ], $actor);

        if ($part->isFirst()) {
            $submission->setSentAt($at);
            $this->completion->onPetitionAccepted($submission, $actor);
        }

        $this->continueOrSettle($submission);
    }

    /**
     * A failure that ends this part's tries.
     *
     * @param FilingPartStatus $failure one of the FAILED_* statuses
     */
    public function failed(FilingEmailPart $part, FilingPartStatus $failure, ?SmtpFailure $smtp, string $error, ?User $actor = null): void
    {
        $submission = $part->getSubmission();
        if ($smtp === null && $failure === FilingPartStatus::FAILED_TEMPORARY_EXHAUSTED) {
            // The last temporary answer stays the reason shown.
            $part->recordFailure($failure, $part->getLastSmtpCode(), $part->getLastEnhancedStatus(), $part->getLastError() ?? $error);
        } else {
            $part->recordFailure($failure, $smtp?->smtpCode, $smtp?->enhancedStatus, $smtp?->safeMessage ?? $error);
        }

        $addressInvalidated = false;
        $address = $submission->getCourtEmailAddress();
        // An address that already took the petition is not "unknown" because an annex bounced.
        if ($failure === FilingPartStatus::FAILED_PERMANENT && $smtp?->invalidatesCourtAddress === true
            && !$submission->isPetitionAccepted() && $address !== null) {
            $details = [
                'smtpCode' => $smtp->smtpCode,
                'enhancedStatus' => $smtp->enhancedStatus,
                'recipientOverridden' => $submission->isRecipientOverridden(),
            ];
            try {
                $this->addresses->markInvalid($address, 'smtp_rejected', null, $submission, $details);
                $addressInvalidated = true;
            } catch (CourtEmailActionRefusedException) {
                $this->audit($submission, 'filing_court_address_already_invalid', ['courtEmailAddressId' => $address->getId()] + $details, $actor);
            }
        }

        $this->audit($submission, 'filing_part_failed', [
            'sequence' => $part->getSequence(),
            'status' => $failure->value,
            'smtpCode' => $smtp?->smtpCode,
            'enhancedStatus' => $smtp?->enhancedStatus,
            'stage' => $smtp?->stage,
            'error' => $smtp === null ? $error : null,
            'addressInvalidated' => $addressInvalidated,
        ], $actor);

        $this->settle($submission);

        if ($submission->isPetitionAccepted() && $submission->isStalled()) {
            $this->notifier->partiallySent($submission, $part);

            return;
        }
        match ($failure) {
            FilingPartStatus::FAILED_TEMPORARY_EXHAUSTED => $this->notifier->failedTemporary($submission, $part, FilingNotifier::VARIANT_EXHAUSTED),
            FilingPartStatus::FAILED_PERMANENT => $this->notifier->failedPermanent($submission, $part, $addressInvalidated),
            FilingPartStatus::FAILED_SIZE => $this->notifier->failedSize($submission, $part, $this->resplit->isResplittable($submission)),
            default => $this->notifier->failedInternal($submission, $part),
        };
    }

    /** Whether the server took the message is unknown: it is never sent again on its own. */
    public function uncertain(FilingEmailPart $part, string $why): void
    {
        $submission = $part->getSubmission();
        $part->setStatus(FilingPartStatus::UNCERTAIN);
        $this->audit($submission, 'filing_part_uncertain', ['sequence' => $part->getSequence(), 'why' => $why]);
        $this->settle($submission);
    }

    /**
     * Recomputes the submission from its parts. Idempotent: a call with nothing new
     * changes nothing.
     */
    public function settle(FilingSubmission $submission): void
    {
        if (!$submission->isActive()) {
            return;
        }
        $status = $submission->getDeliveryStatus();
        $reason = $submission->getReviewReason();

        if ($submission->hasUncertainPart()) {
            if ($status !== FilingDeliveryStatus::NEEDS_REVIEW) {
                $this->review($submission, self::REVIEW_UNCERTAIN);
            }

            return;
        }
        if ($status === FilingDeliveryStatus::NEEDS_REVIEW && $reason === self::REVIEW_UNCERTAIN) {
            $submission->clearReview();
            $status = $submission->getDeliveryStatus();
        }

        // Registered on another path while a part was still in doubt: close once it is answered.
        if ($status === FilingDeliveryStatus::NEEDS_REVIEW && $reason === self::REVIEW_ANNEXES_CANCELLED_PENDING) {
            if (!$submission->hasPartWithStatus(FilingPartStatus::SENDING)) {
                foreach ($submission->getParts() as $part) {
                    if ($part->getStatus()->isCancellable()) {
                        $part->setStatus(FilingPartStatus::CANCELLED);
                    }
                }
                $this->closePartial($submission, 'registration');
            }

            return;
        }

        if ($submission->allLivePartsAccepted()) {
            $submission->setSentAt($submission->getPart(1)?->getSentAt());
            if ($submission->hasPartWithStatus(FilingPartStatus::CANCELLED)) {
                $this->closePartial($submission, 'all_remaining_accepted');

                return;
            }
            $submission->setDeliveryStatus(FilingDeliveryStatus::SENT);
            $submission->release();
            $this->audit($submission, 'filing_submission_sent', []);
            $this->filingLock->forget($submission->getLegalCase());

            return;
        }

        // The petition failed: nothing reached the court, and the annexes never leave without it.
        $first = $submission->getPart(1);
        if ($first !== null && $first->isFailed()) {
            foreach ($submission->getParts() as $part) {
                if ($part->getStatus() === FilingPartStatus::PENDING) {
                    $part->setStatus(FilingPartStatus::CANCELLED);
                }
            }
            $terminal = FilingDeliveryStatus::from($first->getStatus()->value);
            // The package stays SIGNED: the signatures do not depend on the address.
            $submission->failTerminal($terminal);
            $this->audit($submission, 'filing_submission_failed', ['status' => $terminal->value]);
            $this->filingLock->forget($submission->getLegalCase());

            return;
        }

        // A stop for the lawyer (a condition in the worker, or the case moved on) waits for them.
        if ($status === FilingDeliveryStatus::NEEDS_REVIEW || $submission->hasOpenPart() || !$submission->hasFailedPart()
            || !$submission->isPetitionAccepted()) {
            return;
        }

        $submission->setDeliveryStatus(FilingDeliveryStatus::PARTIALLY_SENT);
        $this->audit($submission, 'filing_submission_stalled', ['accepted' => $submission->countAcceptedParts()]);
    }

    public function review(FilingSubmission $submission, string $reasonKey, ?User $actor = null): void
    {
        $submission->markNeedsReview($reasonKey);
        $this->audit($submission, 'filing_submission_needs_review', ['reason' => $reasonKey], $actor);
        $this->notifier->needsReview($submission, $reasonKey);
    }

    public function closePartial(FilingSubmission $submission, string $why, ?User $actor = null): void
    {
        $submission->closePartial($this->clock->now());
        $this->audit($submission, 'filing_submission_closed_partial', [
            'why' => $why,
            'accepted' => $submission->countAcceptedParts(),
            'parts' => array_map(static fn (FilingEmailPart $p): string => $p->getSequence() . ':' . $p->getStatus()->value, $submission->getParts()->toArray()),
        ], $actor);
        $this->filingLock->forget($submission->getLegalCase());
    }

    /** After an acceptance: the next email leaves, unless the submission stopped or nothing is left. */
    private function continueOrSettle(FilingSubmission $submission): void
    {
        if ($submission->getDeliveryStatus() === FilingDeliveryStatus::NEEDS_REVIEW
            && $submission->getReviewReason() === self::REVIEW_UNCERTAIN && !$submission->hasUncertainPart()) {
            $submission->clearReview();
        }

        if ($submission->isActive() && $submission->getDeliveryStatus() !== FilingDeliveryStatus::NEEDS_REVIEW
            && !$submission->hasPartWithStatus(FilingPartStatus::SENDING, FilingPartStatus::RETRY_SCHEDULED)) {
            $next = $submission->firstUnacceptedPart();
            if ($next !== null && $next->getStatus() === FilingPartStatus::PENDING) {
                if ($submission->isPetitionAccepted()) {
                    $submission->setDeliveryStatus(FilingDeliveryStatus::PARTIALLY_SENT);
                }
                $this->em->flush();
                // Same connection as the Doctrine transport: the message exists only if this commits.
                $this->bus->dispatch(new SendFilingEmailPartMessage((int) $next->getId()));

                return;
            }
        }

        $this->settle($submission);
    }

    /** @param array<string, mixed> $data */
    private function audit(FilingSubmission $submission, string $action, array $data, ?User $actor = null): void
    {
        $this->auditLogService->log(
            action: $action,
            entityType: LegalCase::class,
            entityId: (string) $submission->getLegalCase()->getId(),
            newData: ['submissionId' => $submission->getId(), ...$data],
            category: AuditLogService::CATEGORY_FILING_SUBMISSION,
            actor: $actor ?? $submission->getInitiatedBy(),
        );
    }
}
