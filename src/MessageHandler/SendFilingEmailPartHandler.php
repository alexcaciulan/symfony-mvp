<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\FilingAttempt;
use App\Entity\FilingEmailPart;
use App\Entity\FilingSubmission;
use App\Entity\LegalCase;
use App\Enum\FilingAttemptOutcome;
use App\Enum\FilingAttemptSource;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingPartStatus;
use App\Message\SendFilingEmailPartMessage;
use App\Service\AuditLogService;
use App\Service\Filing\BuiltFilingEmail;
use App\Service\Filing\CourtFilingEmailException;
use App\Service\Filing\CourtFilingEmailFactory;
use App\Service\Filing\CourtFilingNotAllowedException;
use App\Service\Filing\FilingCompletion;
use App\Service\Filing\FilingEmlStorage;
use App\Service\Filing\FilingLockQuery;
use App\Service\Filing\FilingSendGuard;
use App\Service\Filing\RecipientOverrideGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends one email of a court filing on the `court_filing` worker. The part is
 * marked SENDING and its exact bytes written to disk in a committed transaction
 * before the SMTP hand-off, so a crash mid-send leaves a part that is recognised
 * as uncertain instead of being sent a second time. The conditions are checked
 * again here, since time passed since the lawyer's request. Every failure stops
 * the submission for review without an automatic resend; classifying temporary
 * SMTP errors for retries is a separate concern.
 */
#[AsMessageHandler]
final class SendFilingEmailPartHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FilingSendGuard $guard,
        private readonly RecipientOverrideGuard $recipientGuard,
        private readonly CourtFilingEmailFactory $emailFactory,
        private readonly FilingEmlStorage $emlStorage,
        private readonly FilingCompletion $completion,
        private readonly FilingLockQuery $filingLock,
        private readonly AuditLogService $auditLogService,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly TransportInterface $courtFilingTransport,
        private readonly int $filingMaxEmailBytes,
    ) {}

    public function __invoke(SendFilingEmailPartMessage $message): void
    {
        $prepared = $this->prepare($message->partId);
        if ($prepared === null) {
            return;
        }
        [$part, $attempt, $built] = $prepared;

        try {
            $this->courtFilingTransport->send(new RawMessage($built->bytes), $built->envelope);
        } catch (\Throwable $e) {
            $this->inTransaction(function () use ($part, $attempt, $e): void {
                $code = $e instanceof TransportExceptionInterface && $e->getCode() >= 200 ? $e->getCode() : null;
                $attempt->finish(FilingAttemptOutcome::INTERNAL, $this->clock->now(), $code, null, $e::class, 'SMTP hand-off failed');
                $part->recordFailure(FilingPartStatus::FAILED_INTERNAL, $code, null, $e::class);
                $this->stopForReview($part->getSubmission(), 'filing.status.review.send_failed');
            });

            throw new UnrecoverableMessageHandlingException('Court filing email was not accepted.', 0, $e);
        }

        $this->inTransaction(function () use ($part, $attempt): void {
            $now = $this->clock->now();
            $attempt->finish(FilingAttemptOutcome::ACCEPTED, $now);
            $part->markAccepted($now);
            $submission = $part->getSubmission();
            $this->audit($submission, 'filing_part_accepted', ['sequence' => $part->getSequence(), 'messageId' => $part->getMessageId(), 'encodedBytes' => $part->getEncodedBytes()]);

            if ($part->isFirst()) {
                $this->completion->onPetitionAccepted($submission);
            }
            if ($submission->getDeliveryStatus() === FilingDeliveryStatus::NEEDS_REVIEW) {
                return;
            }

            if ($part->isLast()) {
                $submission->setDeliveryStatus(FilingDeliveryStatus::SENT);
                $submission->setSentAt($submission->getPart(1)?->getSentAt());
                $submission->release();
                $this->filingLock->forget($submission->getLegalCase());

                return;
            }

            $submission->setDeliveryStatus(FilingDeliveryStatus::PARTIALLY_SENT);
            $submission->setSentAt($submission->getPart(1)?->getSentAt());
            $next = $submission->getPart($part->getSequence() + 1);
            $this->em->flush();
            if ($next !== null) {
                $this->bus->dispatch(new SendFilingEmailPartMessage((int) $next->getId()));
            }
        });
    }

    /**
     * Claims the part and writes its bytes, or decides it must not be sent.
     *
     * @return array{0: FilingEmailPart, 1: FilingAttempt, 2: BuiltFilingEmail}|null
     */
    private function prepare(int $partId): ?array
    {
        $failure = null;
        $result = $this->inTransaction(function () use ($partId, &$failure): ?array {
            $part = $this->em->find(FilingEmailPart::class, $partId);
            if (!$part instanceof FilingEmailPart) {
                return null;
            }
            $this->em->lock($part, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($part);
            $submission = $part->getSubmission();
            $this->em->refresh($submission);

            if ($part->getStatus() === FilingPartStatus::SENDING) {
                // A previous run died between the claim and the answer: whether the server
                // took the message is unknown, and sending again could file the petition twice.
                $attempt = new FilingAttempt($part, $part->getAttemptCount() + 1, FilingAttemptSource::SMTP, $this->clock->now());
                $attempt->finish(FilingAttemptOutcome::UNCERTAIN, $this->clock->now(), message: 'Found mid-send on redelivery');
                $this->em->persist($attempt);
                $part->setStatus(FilingPartStatus::UNCERTAIN);
                $this->stopForReview($submission, 'filing.status.review.part_uncertain');

                return null;
            }
            if ($part->getStatus() !== FilingPartStatus::PENDING || !$submission->isActive()
                || $submission->getDeliveryStatus() === FilingDeliveryStatus::NEEDS_REVIEW) {
                return null;
            }

            $verdict = $this->guard->checkForPart($part);
            if (!$verdict->isAllowed()) {
                $this->stopForReview($submission, (string) $verdict->firstBlocker());

                return null;
            }

            $attempt = new FilingAttempt($part, $part->startAttempt(), FilingAttemptSource::SMTP, $this->clock->now());
            $this->em->persist($attempt);
            if ($submission->getDeliveryStatus() === FilingDeliveryStatus::QUEUED) {
                $submission->setDeliveryStatus(FilingDeliveryStatus::SENDING);
            }

            try {
                $to = $this->recipientGuard->resolve($submission->getIntendedToAddress(), $submission->getCcAddress());
                if ($to !== $submission->getToAddress()) {
                    throw new CourtFilingEmailException('filing.status.review.recipient_changed');
                }
                $built = $this->emailFactory->build($part, $to, $this->clock->now());
                if ($built->encodedBytes > $this->filingMaxEmailBytes) {
                    throw new CourtFilingEmailException('filing.status.review.too_large');
                }
                $path = $this->emlStorage->write($part, $built->bytes);
                $part->recordEml($path, $built->sha256, $built->encodedBytes);
            } catch (CourtFilingEmailException | CourtFilingNotAllowedException | \RuntimeException $e) {
                $key = $e instanceof CourtFilingEmailException ? $e->messageKey : 'filing.status.review.internal_error';
                $attempt->finish(FilingAttemptOutcome::INTERNAL, $this->clock->now(), errorClass: $e::class, message: $key);
                $part->recordFailure(FilingPartStatus::FAILED_INTERNAL, null, null, $key);
                $this->stopForReview($submission, $key);
                $failure = $e;

                return null;
            }

            return [$part, $attempt, $built];
        });

        if ($failure !== null) {
            throw new UnrecoverableMessageHandlingException('Court filing email could not be prepared.', 0, $failure);
        }

        return $result;
    }

    private function stopForReview(FilingSubmission $submission, string $reasonKey): void
    {
        $submission->markNeedsReview($reasonKey);
        $this->audit($submission, 'filing_submission_needs_review', ['reason' => $reasonKey]);
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

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }
}
