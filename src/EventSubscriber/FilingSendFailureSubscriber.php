<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\FilingEmailPart;
use App\Enum\FilingAttemptOutcome;
use App\Enum\FilingPartStatus;
use App\Message\SendFilingEmailPartMessage;
use App\Service\Filing\FilingNotifier;
use App\Service\Filing\FilingPartOutcomeApplier;
use App\Service\Filing\FilingRowLocker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Closes the loop on a court email the handler left waiting for a retry. Runs
 * after Messenger's own retry listener (priority 100), so willRetry() is final:
 * with a retry ahead, the next try's time is recorded and the lawyer is told at
 * the first failure of a round that it is being retried; with none left, the part
 * fails for good and the submission is settled (ended, or partially sent).
 *
 * It never throws: an exception here would stop Worker::ack() before the
 * message is rejected, crash the consumer and leave a second live copy. A
 * manager closed by the handler's failed flush is reset first.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class, priority: 0)]
final readonly class FilingSendFailureSubscriber
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private FilingRowLocker $locker,
        private LoggerInterface $logger,
        private FilingPartOutcomeApplier $outcomes,
        private FilingNotifier $notifier,
        private ClockInterface $clock,
        #[Autowire(service: 'messenger.retry_strategy_locator')]
        private ContainerInterface $retryStrategies,
    ) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        if (!$message instanceof SendFilingEmailPartMessage) {
            return;
        }

        try {
            if (!$this->em->isOpen()) {
                $this->registry->resetManager();
            }
            $this->record($message->partId, $event);
        } catch (\Throwable $e) {
            $this->logger->error('filing.send_failure.bookkeeping_failed', [
                'partId' => $message->partId,
                'exceptionClass' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function record(int $partId, WorkerMessageFailedEvent $event): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $part = $this->em->find(FilingEmailPart::class, $partId);
            if ($part instanceof FilingEmailPart) {
                $this->locker->lock($part->getSubmission());
                $event->willRetry() ? $this->onRetry($part, $event) : $this->onExhausted($part);
            }
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    private function onRetry(FilingEmailPart $part, WorkerMessageFailedEvent $event): void
    {
        if ($part->getStatus() !== FilingPartStatus::RETRY_SCHEDULED) {
            return;
        }
        $attempt = $part->latestSmtpAttempt();
        if ($attempt === null || $attempt->getOutcome() !== FilingAttemptOutcome::TEMPORARY) {
            return;
        }

        $strategy = $this->retryStrategies->has($event->getReceiverName()) ? $this->retryStrategies->get($event->getReceiverName()) : null;
        $waitMs = $strategy instanceof RetryStrategyInterface ? $strategy->getWaitingTime($event->getEnvelope(), $event->getThrowable()) : 0;
        $next = $this->clock->now()->modify('+' . intdiv($waitMs, 1000) . ' seconds');
        $attempt->setNextRetryAt($next);

        // Told once per round (the first SMTP failure); the later retries are silent.
        if (RedeliveryStamp::getRetryCountFromEnvelope($event->getEnvelope()) === 0) {
            $this->notifier->failedTemporary($part->getSubmission(), $part, FilingNotifier::VARIANT_RETRYING, $next);
        }
    }

    private function onExhausted(FilingEmailPart $part): void
    {
        // PENDING: the claim was rolled back by a database failure on every try.
        if (!in_array($part->getStatus(), [FilingPartStatus::RETRY_SCHEDULED, FilingPartStatus::PENDING], true)
            || !$part->getSubmission()->isActive()) {
            return;
        }

        $this->outcomes->failed($part, FilingPartStatus::FAILED_TEMPORARY_EXHAUSTED, null, $part->getLastError() ?? 'filing.failure.kind.temporary');
    }
}
