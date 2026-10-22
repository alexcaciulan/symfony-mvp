<?php

declare(strict_types=1);

namespace App\Service\Filing;

use App\Entity\CourtEmailAddress;
use App\Entity\FilingEmailPart;
use App\Entity\FilingPackage;
use App\Entity\FilingPackagePart;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingPartStatus;
use App\Enum\InboundReviewStatus;
use App\Message\SendFilingEmailPartMessage;
use App\Repository\FilingSubmissionRepository;
use App\Repository\InboundMailRepository;
use App\Service\AuditLogService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Queues the transmission of a signed package to the court, on the lawyer's
 * request, and the two ways it stops before the end: the lawyer cancels it before
 * anything left, or the case gets its court number on another path while annexes
 * are still waiting.
 */
final class FilingSubmissionService
{
    public const CONFIRM_DOCUMENTS = 'documents_checked';
    public const CONFIRM_OTHER_CHANNEL = 'other_channel';
    public const CONFIRM_CLOSE_INCOMPLETE = 'incomplete';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilingSubmissionRepository $submissions,
        private readonly FilingSendGuard $guard,
        private readonly CourtFilingEmailFactory $emailFactory,
        private readonly RecipientOverrideGuard $recipientGuard,
        private readonly FilingLockQuery $filingLock,
        private readonly FilingNotifier $notifier,
        private readonly AuditLogService $auditLogService,
        private readonly MessageBusInterface $bus,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactory $filingSubmissionLimiter,
        private readonly RateLimiterFactory $filingCourtCapLimiter,
        private readonly FilingPartOutcomeApplier $outcomes,
        private readonly InboundMailRepository $inboundMails,
    ) {}

    /**
     * The statements the lawyer confirms, in the words shown to them.
     *
     * @return array<string, string> key => text
     */
    public function confirmationTexts(LegalCase $case): array
    {
        $texts = [self::CONFIRM_DOCUMENTS => $this->translator->trans('filing.send.confirm.documents_checked')];
        if ($this->guard->requiresOtherChannelConfirmation($case)) {
            $texts[self::CONFIRM_OTHER_CHANNEL] = $this->translator->trans('filing.send.confirm.other_channel');
        }

        return $texts;
    }

    /**
     * Queues the submission and its first email. A second request while one holds
     * the case returns that one: a double click never sends twice.
     *
     * @param array<string, bool> $confirmed statement key => ticked
     *
     * @throws FilingSendBlockedException
     */
    public function submit(LegalCase $case, User $user, array $confirmed, ?string $ip, ?string $sessionHash): FilingSubmission
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($case, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->submissions->findActiveForCase($case);
            if ($existing !== null) {
                $connection->commit();

                return $existing;
            }

            $verdict = $this->guard->checkForSubmit($case, $user);
            $package = $verdict->package;
            $address = $verdict->address;
            if (!$verdict->isAllowed() || $package === null || $address === null) {
                throw new FilingSendBlockedException($verdict->firstBlocker() ?? 'filing.send.blocker.package_not_signed', $verdict->blockers);
            }
            $this->em->lock($package, LockMode::PESSIMISTIC_WRITE);

            $texts = $this->confirmationTexts($case);
            $confirmations = [];
            foreach ($texts as $key => $text) {
                if (($confirmed[$key] ?? false) !== true) {
                    throw new FilingSendBlockedException('filing.send.error.confirmation_required');
                }
                $confirmations[] = ['key' => $key, 'text' => $text];
            }

            $lawyerEmail = mb_strtolower(trim((string) $user->getEmail()));
            try {
                $to = $this->recipientGuard->resolve($address->getEmail(), $lawyerEmail);
            } catch (CourtFilingNotAllowedException $e) {
                throw new FilingSendBlockedException('filing.send.blocker.configuration', [], $e);
            }

            // Consumed last, so a refused request does not use up the lawyer's daily allowance.
            if (!$this->filingSubmissionLimiter->create((string) $user->getId())->consume()->isAccepted()
                || !$this->filingCourtCapLimiter->create('court_' . $address->getCourt()->getId())->consume()->isAccepted()) {
                throw new FilingSendBlockedException('filing.send.error.rate_limited');
            }

            $submission = $this->createSubmission($case, $package, $user, $address, $to, $lawyerEmail, $confirmations, $ip, $sessionHash);
            $this->em->persist($submission);
            $this->em->flush();

            $this->auditLogService->log(
                action: 'filing_submission_queued',
                entityType: LegalCase::class,
                entityId: (string) $case->getId(),
                newData: [
                    'submissionId' => $submission->getId(),
                    'packageVersion' => $package->getVersion(),
                    'parts' => $submission->getParts()->count(),
                    'courtEmailAddressId' => $address->getId(),
                    'recipientOverridden' => $submission->isRecipientOverridden(),
                    'templateVersion' => $submission->getTemplateVersion(),
                    'confirmations' => $confirmations,
                    'ip' => $ip,
                    'sessionHash' => $sessionHash,
                ],
                category: AuditLogService::CATEGORY_FILING_SUBMISSION,
                actor: $user,
            );
            $this->em->flush();

            // Same connection as the Doctrine transport: the message exists only if the submission does.
            $this->bus->dispatch(new SendFilingEmailPartMessage((int) $submission->getPart(1)?->getId()));
            $connection->commit();
            $this->filingLock->forget($case);

            return $submission;
        } catch (UniqueConstraintViolationException $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw new FilingSendBlockedException('filing.send.blocker.submission_active', [], $e);
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Whether nothing can have reached a mail server: no part accepted, none caught
     * mid-send and none whose outcome is unknown. A part waiting for a retry, or one
     * refused for good, never reached the court.
     */
    public function isCancellable(FilingSubmission $submission): bool
    {
        return $submission->isActive()
            && $submission->getReviewReason() !== FilingPartOutcomeApplier::REVIEW_LATE_BOUNCE
            && !$submission->hasPartWithStatus(FilingPartStatus::ACCEPTED, FilingPartStatus::SENDING, FilingPartStatus::UNCERTAIN);
    }

    public function isLateBounceReview(FilingSubmission $submission): bool
    {
        return $submission->isActive()
            && $submission->getDeliveryStatus() === FilingDeliveryStatus::NEEDS_REVIEW
            && $submission->getReviewReason() === FilingPartOutcomeApplier::REVIEW_LATE_BOUNCE;
    }

    /**
     * The lawyer checked a late bounce on a case that had already moved on (court
     * number, declared date or confirmed portal match): nothing on the case is
     * undone, the review ends and the case is free again.
     *
     * @throws FilingSendBlockedException
     */
    public function acknowledgeLateBounce(FilingSubmission $submission, User $user): void
    {
        if ($submission->getLegalCase()->getUser() !== $user) {
            throw new FilingSendBlockedException('filing.send.blocker.not_owner');
        }
        $this->inLockedSubmission($submission, function () use ($submission, $user): void {
            if (!$this->isLateBounceReview($submission)) {
                throw new FilingSendBlockedException('filing.undelivered.ack_refused');
            }
            $submission->endLateReview();
            foreach ($this->inboundMails->findForSubmission($submission) as $mail) {
                if ($mail->getReviewStatus() === InboundReviewStatus::PENDING && $mail->getReviewReason() === 'late_bounce_case_advanced') {
                    $mail->resolveReview();
                }
            }
            $submission->appendConfirmation('late_bounce', ['checked' => $this->translator->trans('filing.undelivered.ack_statement')], $user, $this->clock->now());
            $this->auditLogService->log(
                action: 'filing_late_bounce_acknowledged',
                entityType: LegalCase::class,
                entityId: (string) $submission->getLegalCase()->getId(),
                newData: ['submissionId' => $submission->getId(), 'status' => $submission->getDeliveryStatus()->value],
                category: AuditLogService::CATEGORY_FILING_SUBMISSION,
                actor: $user,
            );
        });
    }

    /** The petition left; what did not can be closed so the case is free again. */
    public function isClosable(FilingSubmission $submission): bool
    {
        return $submission->isActive()
            && $submission->isPetitionAccepted()
            && !$submission->hasPartWithStatus(FilingPartStatus::SENDING, FilingPartStatus::UNCERTAIN)
            && ($submission->firstUnacceptedPart() !== null || $submission->getDeliveryStatus() === FilingDeliveryStatus::NEEDS_REVIEW);
    }

    /**
     * The statements the lawyer confirms to close a submission whose petition left
     * without every annex.
     *
     * @return array<string, string> key => text
     */
    public function closeConfirmationTexts(): array
    {
        return [self::CONFIRM_CLOSE_INCOMPLETE => $this->translator->trans('filing.close.confirm.incomplete')];
    }

    /**
     * Only before anything reached a server: once the petition was accepted, it
     * cannot be called back. Failed parts keep their status as evidence.
     *
     * @throws FilingSendBlockedException
     */
    public function cancel(FilingSubmission $submission, User $user): void
    {
        if ($submission->getLegalCase()->getUser() !== $user) {
            throw new FilingSendBlockedException('filing.send.blocker.not_owner');
        }

        $this->inLockedSubmission($submission, function () use ($submission, $user): void {
            $this->cancelLocked($submission, $user);
        });
    }

    /**
     * Closes, on the lawyer's request, a submission whose petition left: the emails
     * still waiting are cancelled, failed ones keep their status as evidence, and the
     * case is free again. The court has the petition without the remaining annexes,
     * which the lawyer files on another channel; the stored copies stay.
     *
     * @param array<string, bool> $confirmed statement key => ticked
     *
     * @throws FilingSendBlockedException
     */
    public function closeRemaining(FilingSubmission $submission, User $user, array $confirmed): void
    {
        $case = $submission->getLegalCase();
        if ($case->getUser() !== $user) {
            throw new FilingSendBlockedException('filing.send.blocker.not_owner');
        }

        $this->inLockedSubmission($submission, function () use ($submission, $user, $confirmed): void {
            $this->closeLocked($submission, $user, $confirmed, 'lawyer');
        });
    }

    /**
     * The same close, done by an admin to unblock a case: operational only, with
     * the reason audited. It declares nothing about what the court received.
     *
     * @throws FilingSendBlockedException
     */
    public function closeByAdmin(FilingSubmission $submission, User $admin, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw new FilingSendBlockedException('filing.override.error.reason_required');
        }
        $this->inLockedSubmission($submission, function () use ($submission, $admin, $reason): void {
            if ($this->isCancellable($submission)) {
                $this->cancelLocked($submission, $admin, ['adminReason' => trim($reason)]);

                return;
            }
            if (!$this->isClosable($submission)) {
                throw new FilingSendBlockedException('filing.close.refused');
            }
            $this->closeParts($submission);
            $this->outcomes->closePartial($submission, 'admin: ' . mb_substr(trim($reason), 0, 200), $admin);
        });
    }

    /**
     * The case was registered on another path (portal number, manual entry, admin)
     * while emails were still waiting: nothing more is sent, and the lawyer is told
     * exactly which documents did not leave. A part whose outcome is not known yet
     * keeps the submission open for review until it is answered. Runs inside the
     * caller's transaction. Copies, attempts and hashes are kept.
     */
    public function cancelRemainingOnRegistration(LegalCase $case): void
    {
        $submission = $this->submissions->findActiveForCase($case);
        if ($submission === null) {
            return;
        }

        $sent = [];
        $notSent = [];
        foreach ($submission->getParts() as $part) {
            if ($part->getStatus() === FilingPartStatus::ACCEPTED) {
                $sent = [...$sent, ...$this->titles($part)];
            } elseif ($part->getStatus()->isCancellable()) {
                $part->setStatus(FilingPartStatus::CANCELLED);
                $notSent = [...$notSent, ...$this->titles($part)];
            } elseif ($part->isFailed()) {
                $notSent = [...$notSent, ...$this->titles($part)];
            }
        }

        if ($sent === [] && !$submission->hasPartWithStatus(FilingPartStatus::SENDING, FilingPartStatus::UNCERTAIN)) {
            $submission->markCancelled($this->clock->now());
        } elseif ($submission->hasPartWithStatus(FilingPartStatus::SENDING, FilingPartStatus::UNCERTAIN)) {
            $this->outcomes->review($submission, FilingPartOutcomeApplier::REVIEW_ANNEXES_CANCELLED_PENDING);
        } else {
            $this->outcomes->closePartial($submission, 'registration');
        }
        if ($sent !== [] && $notSent !== []) {
            $this->notifier->annexesCancelled($submission, $sent, $notSent);
        }

        $this->auditLogService->log(
            action: 'filing_submission_parts_cancelled_on_registration',
            entityType: LegalCase::class,
            entityId: (string) $case->getId(),
            newData: ['submissionId' => $submission->getId(), 'sent' => $sent, 'notSent' => $notSent, 'status' => $submission->getDeliveryStatus()->value],
            category: AuditLogService::CATEGORY_FILING_SUBMISSION,
        );
        $this->filingLock->forget($case);
    }

    /** @param array<string, bool> $confirmed */
    private function closeLocked(FilingSubmission $submission, User $user, array $confirmed, string $why): void
    {
        if (!$this->isClosable($submission)) {
            throw new FilingSendBlockedException('filing.close.refused');
        }
        $texts = $this->closeConfirmationTexts();
        foreach (array_keys($texts) as $key) {
            if (($confirmed[$key] ?? false) !== true) {
                throw new FilingSendBlockedException('filing.send.error.confirmation_required');
            }
        }
        $submission->appendConfirmation('close', $texts, $user, $this->clock->now());

        $sent = [];
        $notSent = [];
        foreach ($submission->getParts() as $part) {
            if ($part->getStatus() === FilingPartStatus::ACCEPTED) {
                $sent = [...$sent, ...$this->titles($part)];
            } else {
                $notSent = [...$notSent, ...$this->titles($part)];
            }
        }
        $this->closeParts($submission);
        $this->outcomes->closePartial($submission, $why, $user);
        if ($notSent !== []) {
            $this->notifier->annexesCancelled($submission, $sent, $notSent);
        }
    }

    /** Waiting emails are cancelled; failed ones keep their status as evidence. */
    private function closeParts(FilingSubmission $submission): void
    {
        foreach ($submission->getParts() as $part) {
            if ($part->getStatus()->isCancellable()) {
                $part->setStatus(FilingPartStatus::CANCELLED);
            }
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function cancelLocked(FilingSubmission $submission, User $user, array $extra = []): void
    {
        if (!$this->isCancellable($submission)) {
            throw new FilingSendBlockedException('filing.status.cancel_refused');
        }
        foreach ($submission->getParts() as $part) {
            if (!$part->isFailed()) {
                $part->setStatus(FilingPartStatus::CANCELLED);
            }
        }
        $submission->markCancelled($this->clock->now());
        $this->auditLogService->log(
            action: 'filing_submission_cancelled',
            entityType: LegalCase::class,
            entityId: (string) $submission->getLegalCase()->getId(),
            newData: ['submissionId' => $submission->getId(), ...$extra],
            category: AuditLogService::CATEGORY_FILING_SUBMISSION,
            actor: $user,
        );
    }

    /** @param callable(): void $work */
    private function inLockedSubmission(FilingSubmission $submission, callable $work): void
    {
        $case = $submission->getLegalCase();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $this->em->lock($case, LockMode::PESSIMISTIC_WRITE);
            $this->em->lock($submission, LockMode::PESSIMISTIC_WRITE);
            foreach ($submission->getParts() as $part) {
                $this->em->lock($part, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($part);
            }
            $this->em->refresh($submission);
            $work();
            $this->em->flush();
            $connection->commit();
            $this->filingLock->forget($case);
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /** @param list<array{key: string, text: string}> $confirmations */
    private function createSubmission(
        LegalCase $case,
        FilingPackage $package,
        User $user,
        CourtEmailAddress $address,
        string $to,
        string $lawyerEmail,
        array $confirmations,
        ?string $ip,
        ?string $sessionHash,
    ): FilingSubmission {
        $token = self::token();
        $submission = new FilingSubmission($case, $package, $address->getCourt(), $user, $token, CourtFilingEmailFactory::TEMPLATE_VERSION, $this->clock->now());
        $submission->setCourtEmailAddress($address);
        $submission->setConfirmations($confirmations);
        $submission->setInitiatedFrom($ip, $sessionHash);
        $submission->setAddressStateSnapshot([
            'id' => $address->getId(),
            'email' => $address->getEmail(),
            'status' => $address->getStatus()->value,
            'purpose' => $address->getPurpose()->value,
            'source' => $address->getSource()->value,
            'validatedById' => $address->getValidatedBy()?->getId(),
            'validatedByName' => $address->getValidatedBy()?->getFullName(),
            'validatedAt' => $address->getValidatedAt()?->format(\DateTimeInterface::ATOM),
            'expiresAt' => $address->getValidatedAt()?->modify('+' . CourtEmailAddress::VALIDATION_TTL_DAYS . ' days')->format(\DateTimeInterface::ATOM),
        ]);

        $emails = $this->emailFactory->emailsOf($package);
        $total = count($emails);
        $firstMessageId = $this->emailFactory->messageId($token, 1);
        $firstVerp = null;
        foreach ($emails as $i => $packageParts) {
            $sequence = $i + 1;
            $verp = bin2hex(random_bytes(12));
            $firstVerp ??= $verp;
            $part = new FilingEmailPart(
                $submission,
                $sequence,
                $total,
                $this->emailFactory->subject($case, $sequence, $total),
                $this->emailFactory->messageId($token, $sequence),
                $verp,
            );
            foreach ($packageParts as $packagePart) {
                $part->addPackagePart($packagePart);
            }
            if ($sequence > 1) {
                $part->setThreading($firstMessageId, $firstMessageId);
            }
        }

        $submission->setAddresses(
            $to,
            $address->getEmail(),
            $this->emailFactory->fromAddress(),
            $lawyerEmail,
            $lawyerEmail,
            $this->emailFactory->envelopeSender((string) $firstVerp),
        );

        return $submission;
    }

    /** @return list<string> */
    private function titles(FilingEmailPart $part): array
    {
        return array_map(static fn (FilingPackagePart $p): string => $p->getTitle(), $part->getOrderedPackageParts());
    }

    /** 26 lowercase base32 characters (130 bits): unguessable inside a Message-ID or a VERP address. */
    private static function token(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $token = '';
        foreach (str_split(random_bytes(26)) as $byte) {
            $token .= $alphabet[ord($byte) & 31];
        }

        return $token;
    }
}
