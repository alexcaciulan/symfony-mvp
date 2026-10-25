<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingPartStatus;
use App\Message\SendFilingEmailPartMessage;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends again, on the lawyer's new confirmation, the emails of a submission whose
 * petition was accepted but whose annexes did not all leave: the same submission,
 * the same token, the same Message-ID and thread, so the court sees the missing
 * messages of the same filing. Accepted emails are never touched, which makes a
 * second petition impossible on this path. With nothing accepted the submission
 * has already ended and a new one is requested instead.
 */
final class FilingResumeService
{
    public const CONFIRM_RESUME = 'resume_remaining';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilingRowLocker $locker,
        private readonly FilingSendGuard $guard,
        private readonly FilingLockQuery $filingLock,
        private readonly AuditLogService $auditLogService,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {}

    /** @return array<string, string> key => text as shown */
    public function confirmationTexts(): array
    {
        return [self::CONFIRM_RESUME => $this->translator->trans('filing.resume.confirm')];
    }

    /** The structural conditions; the worker conditions are checked on top when it runs. */
    public function isResumable(FilingSubmission $submission): bool
    {
        if (!$submission->isActive() || !$submission->isPetitionAccepted()) {
            return false;
        }
        $status = $submission->getDeliveryStatus();
        if ($status !== FilingDeliveryStatus::PARTIALLY_SENT && $status !== FilingDeliveryStatus::NEEDS_REVIEW) {
            return false;
        }
        if ($submission->hasPartWithStatus(FilingPartStatus::SENDING, FilingPartStatus::RETRY_SCHEDULED, FilingPartStatus::UNCERTAIN)) {
            return false;
        }
        $next = $submission->firstUnacceptedPart();
        if ($next === null || !($next->isFailed() || $next->getStatus() === FilingPartStatus::PENDING)) {
            return false;
        }
        // A pending part with nothing failed is only stuck behind a review.
        if (!$submission->hasFailedPart() && $status !== FilingDeliveryStatus::NEEDS_REVIEW) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, bool> $confirmed statement key => ticked
     *
     * @throws FilingSendBlockedException
     */
    public function resumeRemaining(FilingSubmission $submission, User $user, array $confirmed, ?string $ip, ?string $sessionHash): void
    {
        $case = $submission->getLegalCase();
        if ($case->getUser() !== $user) {
            throw new FilingSendBlockedException('filing.send.blocker.not_owner');
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->locker->lock($submission);

            if (!$this->isResumable($submission)) {
                throw new FilingSendBlockedException('filing.resume.refused');
            }
            $texts = $this->confirmationTexts();
            foreach (array_keys($texts) as $key) {
                if (($confirmed[$key] ?? false) !== true) {
                    throw new FilingSendBlockedException('filing.send.error.confirmation_required');
                }
            }

            $next = $submission->firstUnacceptedPart() ?? throw new FilingSendBlockedException('filing.resume.refused');
            $verdict = $this->guard->checkForPart($next);
            if (!$verdict->isAllowed()) {
                throw new FilingSendBlockedException($verdict->firstBlocker() ?? 'filing.resume.refused', $verdict->blockers);
            }

            $reset = [];
            foreach ($submission->getParts() as $part) {
                if ($part->isFailed()) {
                    $reset[] = $part->getSequence() . ':' . $part->getStatus()->value;
                    $part->resetForResume();
                }
            }
            $submission->appendConfirmation('resume', $texts, $user, $this->clock->now());
            $submission->clearReview();
            $submission->setDeliveryStatus(FilingDeliveryStatus::PARTIALLY_SENT);

            $this->auditLogService->log(
                action: 'filing_submission_resumed',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'submissionId' => $submission->getId(),
                    'resetParts' => $reset,
                    'nextSequence' => $next->getSequence(),
                    'confirmations' => $texts,
                    'ip' => $ip,
                    'sessionHash' => $sessionHash,
                ],
                category: AuditLogService::CATEGORY_FILING_SUBMISSION,
                actor: $user,
            );
            $this->em->flush();
            // Only the first missing email; the handler chains to the next after each acceptance.
            $this->bus->dispatch(new SendFilingEmailPartMessage((int) $next->getId()));
            $connection->commit();
            $this->filingLock->forget($case);
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }
}
