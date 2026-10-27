<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\FilingAttempt;
use App\Entity\FilingEmailPart;
use App\Enum\FilingAttemptOutcome;
use App\Enum\FilingAttemptSource;
use App\Enum\FilingDeliveryStatus;
use App\Enum\FilingPartStatus;
use App\Message\SendFilingEmailPartMessage;
use App\Service\Filing\BuiltFilingEmail;
use App\Service\Filing\CourtFilingEmailException;
use App\Service\Filing\CourtFilingEmailFactory;
use App\Service\Filing\CourtFilingNotAllowedException;
use App\Service\Filing\FilingEmlStorage;
use App\Service\Filing\FilingPartOutcomeApplier;
use App\Service\Filing\FilingRowLocker;
use App\Service\Filing\FilingSendGuard;
use App\Service\Filing\RecipientOverrideGuard;
use App\Service\Filing\SmtpFailureClassifier;
use App\Service\Filing\SmtpFailureKind;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends one email of a court filing on the `court_filing` worker. The part is
 * marked SENDING and its exact bytes written to disk in a committed transaction
 * before the SMTP hand-off. The conditions are checked again before every try,
 * since time passed since the lawyer's request.
 *
 * Failure policy: a temporary SMTP failure (4xx, connection or TLS before the
 * message was complete) is rethrown so the transport retries it after 5 min,
 * 30 min and 2 h; a permanent or size refusal and an internal error (a signed
 * file missing or changed) end the part at once without using up the retries.
 * A connection lost after the whole message was written, or a part found
 * mid-send past the stale window, is uncertain and never sent again on its own:
 * resending could file the petition twice. A redelivery that finds the part
 * mid-send inside the window looks again later instead of sending.
 *
 * Every transaction locks the case, the submission and the parts in that order
 * (FilingRowLocker), the same as every other writer. Recording an SMTP answer is
 * retried on a deadlock or a lock timeout: the answer is already known, and
 * leaving it unrecorded would turn an accepted petition into an uncertain one.
 */
#[AsMessageHandler]
final class SendFilingEmailPartHandler
{
    private const RECORD_TRIES = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly FilingRowLocker $locker,
        private readonly FilingSendGuard $guard,
        private readonly RecipientOverrideGuard $recipientGuard,
        private readonly CourtFilingEmailFactory $emailFactory,
        private readonly FilingEmlStorage $emlStorage,
        private readonly FilingPartOutcomeApplier $outcomes,
        private readonly SmtpFailureClassifier $classifier,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly TransportInterface $courtFilingTransport,
        private readonly int $filingMaxEmailBytes,
        private readonly int $sendingStaleSeconds,
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
            $this->onSendFailure($part, $attempt, $e);
        }

        $this->recording($part, $attempt, function (FilingEmailPart $part, FilingAttempt $attempt): void {
            $now = $this->clock->now();
            $attempt->finish(FilingAttemptOutcome::ACCEPTED, $now);
            // A definitive answer wins over an "uncertain" set by a recheck meanwhile.
            $this->outcomes->accepted($part, $now, FilingAttemptSource::SMTP);
        });
    }

    /**
     * Records the SMTP answer under the canonical locks, again after a deadlock or
     * a lock timeout with a fresh manager and the rows read again by id.
     *
     * @template T
     *
     * @param callable(FilingEmailPart, FilingAttempt): T $work
     *
     * @return T
     */
    private function recording(FilingEmailPart $part, FilingAttempt $attempt, callable $work): mixed
    {
        $partId = (int) $part->getId();
        $attemptId = (int) $attempt->getId();
        for ($try = 1; ; ++$try) {
            try {
                return $this->inTransaction(function () use ($part, $attempt, $partId, $attemptId, $try, $work): mixed {
                    if ($try > 1) {
                        $part = $this->em->find(FilingEmailPart::class, $partId) ?? throw new \LogicException('Court filing email part gone.');
                        $attempt = $this->em->find(FilingAttempt::class, $attemptId) ?? throw new \LogicException('Court filing attempt gone.');
                    }
                    $this->locker->lock($part->getSubmission());
                    $this->em->refresh($attempt);

                    return $work($part, $attempt);
                });
            } catch (RetryableException $e) {
                if ($try >= self::RECORD_TRIES) {
                    throw $e;
                }
                $this->registry->resetManager();
            }
        }
    }

    /**
     * Claims the part and writes its bytes, or decides it must not be sent now.
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
            $submission = $part->getSubmission();
            $this->locker->lock($submission);
            $now = $this->clock->now();

            if ($part->getStatus() === FilingPartStatus::SENDING) {
                $this->onFoundMidSend($part, $now);

                return null;
            }
            if (!in_array($part->getStatus(), [FilingPartStatus::PENDING, FilingPartStatus::RETRY_SCHEDULED], true)
                || !$submission->isActive() || $submission->getDeliveryStatus() === FilingDeliveryStatus::NEEDS_REVIEW) {
                return null;
            }

            $verdict = $this->guard->checkForPart($part);
            if (!$verdict->isAllowed()) {
                $blocker = (string) $verdict->firstBlocker();
                if ($blocker === 'filing.status.review.signed_file_changed') {
                    $attempt = new FilingAttempt($part, $part->nextAttemptNo(), FilingAttemptSource::SMTP, $now);
                    $attempt->finish(FilingAttemptOutcome::INTERNAL, $now, message: $blocker);
                    $this->em->persist($attempt);
                    $this->outcomes->failed($part, FilingPartStatus::FAILED_INTERNAL, null, $blocker);
                    $failure = new CourtFilingEmailException($blocker);

                    return null;
                }
                // Not retried by the transport any more: the lawyer resumes or cancels.
                if ($part->getStatus() === FilingPartStatus::RETRY_SCHEDULED) {
                    $part->setStatus(FilingPartStatus::PENDING);
                }
                $this->outcomes->review($submission, $blocker);

                return null;
            }

            $attemptNo = $part->nextAttemptNo();
            $attempt = new FilingAttempt($part, $attemptNo, FilingAttemptSource::SMTP, $now);
            $this->em->persist($attempt);

            try {
                $to = $this->recipientGuard->resolve($submission->getIntendedToAddress(), $submission->getCcAddress());
                if ($to !== $submission->getToAddress()) {
                    throw new CourtFilingEmailException('filing.status.review.recipient_changed');
                }
                $built = $this->emailFactory->build($part, $to, $now);
                if ($built->encodedBytes > $this->filingMaxEmailBytes) {
                    $part->recordEncodedBytes($built->encodedBytes);
                    throw new CourtFilingEmailException('filing.status.review.too_large');
                }
                $path = $this->emlStorage->write($part, $attemptNo, $built->bytes);
                $part->recordEml($path, $built->sha256, $built->encodedBytes);
            } catch (CourtFilingEmailException | CourtFilingNotAllowedException | \RuntimeException $e) {
                // Nothing reached a server: these never use up the SMTP retries.
                $key = $e instanceof CourtFilingEmailException ? $e->messageKey : 'filing.status.review.internal_error';
                $tooLarge = $key === 'filing.status.review.too_large';
                $attempt->finish($tooLarge ? FilingAttemptOutcome::SIZE : FilingAttemptOutcome::INTERNAL, $now, errorClass: $e::class, message: $key);
                $this->outcomes->failed($part, $tooLarge ? FilingPartStatus::FAILED_SIZE : FilingPartStatus::FAILED_INTERNAL, null, $key);
                $failure = $e;

                return null;
            }

            $part->startAttempt();
            if ($submission->getDeliveryStatus() === FilingDeliveryStatus::QUEUED) {
                $submission->setDeliveryStatus(FilingDeliveryStatus::SENDING);
            }

            return [$part, $attempt, $built];
        });

        if ($failure !== null) {
            throw new UnrecoverableMessageHandlingException('Court filing email could not be prepared.', 0, $failure);
        }

        return $result;
    }

    /**
     * Another run claimed the part. Inside the stale window it may still be talking
     * to the server, so this one looks again once the window has passed; after it,
     * the run died between the claim and the answer and the outcome is unknown.
     */
    private function onFoundMidSend(FilingEmailPart $part, \DateTimeImmutable $now): void
    {
        $running = $part->latestSmtpAttempt();
        $staleBefore = $now->modify('-' . $this->sendingStaleSeconds . ' seconds');
        if ($running !== null && !$running->isFinished() && $running->getStartedAt() > $staleBefore) {
            $this->bus->dispatch(new Envelope(new SendFilingEmailPartMessage((int) $part->getId()), [new DelayStamp($this->sendingStaleSeconds * 1000)]));

            return;
        }

        $attempt = new FilingAttempt($part, $part->nextAttemptNo(), FilingAttemptSource::SMTP, $now);
        $attempt->finish(FilingAttemptOutcome::UNCERTAIN, $now, message: 'Found mid-send after the stale window');
        $this->em->persist($attempt);
        $this->outcomes->uncertain($part, 'found_mid_send');
    }

    private function onSendFailure(FilingEmailPart $part, FilingAttempt $attempt, \Throwable $e): never
    {
        $kind = $this->recording($part, $attempt, function (FilingEmailPart $part, FilingAttempt $attempt) use ($e): ?SmtpFailureKind {
            $now = $this->clock->now();
            $answered = in_array($part->getStatus(), [FilingPartStatus::SENDING, FilingPartStatus::UNCERTAIN], true);

            if (!$e instanceof TransportExceptionInterface) {
                // Refused by the mailer before any server answered (an invalid address, a broken message).
                $attempt->finish(FilingAttemptOutcome::INTERNAL, $now, errorClass: $e::class, message: 'filing.status.review.internal_error');
                if ($answered) {
                    $this->outcomes->failed($part, FilingPartStatus::FAILED_INTERNAL, null, 'filing.status.review.internal_error');
                }

                return null;
            }

            $failure = $this->classifier->classify($e, $part->getSubmission()->getToAddress());
            $outcome = match ($failure->kind) {
                SmtpFailureKind::TEMPORARY => FilingAttemptOutcome::TEMPORARY,
                SmtpFailureKind::PERMANENT => FilingAttemptOutcome::PERMANENT,
                SmtpFailureKind::SIZE => FilingAttemptOutcome::SIZE,
                SmtpFailureKind::UNCERTAIN => FilingAttemptOutcome::UNCERTAIN,
            };
            $attempt->finish($outcome, $now, $failure->smtpCode, $failure->enhancedStatus, $e::class, $failure->safeMessage);
            if (!$answered) {
                return null;
            }

            match ($failure->kind) {
                SmtpFailureKind::TEMPORARY => $part->scheduleRetry($failure->smtpCode, $failure->enhancedStatus, $failure->safeMessage),
                SmtpFailureKind::PERMANENT => $this->outcomes->failed($part, FilingPartStatus::FAILED_PERMANENT, $failure, $failure->safeMessage),
                SmtpFailureKind::SIZE => $this->outcomes->failed($part, FilingPartStatus::FAILED_SIZE, $failure, $failure->safeMessage),
                SmtpFailureKind::UNCERTAIN => $this->outcomes->uncertain($part, $failure->safeMessage),
            };
            if ($failure->kind === SmtpFailureKind::TEMPORARY) {
                // Leaves a review a recheck may have opened while the server was still answering.
                $this->outcomes->settle($part->getSubmission());
            }

            return $failure->kind;
        });

        if ($kind === SmtpFailureKind::TEMPORARY) {
            // Retryable: the transport's retry strategy schedules the next try.
            throw $e;
        }

        throw new UnrecoverableMessageHandlingException('Court filing email was not accepted.', 0, $e);
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
