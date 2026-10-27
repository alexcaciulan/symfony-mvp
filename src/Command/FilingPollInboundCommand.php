<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\InboundMailRepository;
use App\Repository\InboundPollHeartbeatRepository;
use App\Service\Inbound\InboundEmailIngestor;
use App\Service\Inbound\InboundMailSourceInterface;
use App\Service\Inbound\NullInboundSource;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads the inbound mailbox once: each new message is stored, queued for
 * processing and only then acknowledged at the source, so a crash between the
 * two never loses one (the next run stores it, or finds it already stored).
 * A message that cannot be stored stays unacknowledged for the next run and does
 * not stop the ones after it; the run fails only when the source cannot be read
 * or no message could be stored. Overlapping runs are kept apart by a database
 * lock shared by every container. After reading, a message stored long ago whose
 * processing never completed (its queue message lost or failed) is queued again,
 * a few times at most.
 */
#[AsCommand(name: 'app:filing:poll-inbound', description: 'Read court receipts, delivery reports and replies from the inbound mailbox')]
final class FilingPollInboundCommand extends Command
{
    private const LOCK_NAME = 'lexrecovery_inbound_poll';
    private const REQUEUE_AFTER_MINUTES = 10;

    public function __construct(
        private readonly InboundMailSourceInterface $source,
        private readonly InboundEmailIngestor $ingestor,
        private readonly InboundMailRepository $mails,
        private readonly InboundPollHeartbeatRepository $heartbeats,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of messages to read in this run', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $connection = $this->em->getConnection();
        if ((int) $connection->fetchOne('SELECT GET_LOCK(?, 0)', [self::LOCK_NAME]) !== 1) {
            $io->note('Another inbound poll is running; skipped.');

            return Command::SUCCESS;
        }

        $heartbeat = $this->heartbeats->current();
        $heartbeat->attempted($this->clock->now());
        $this->em->flush();

        try {
            $fetched = 0;
            $stored = 0;
            $failed = 0;
            $lastFailure = null;
            foreach ($this->source->fetch($limit) as $raw) {
                ++$fetched;
                try {
                    if ($this->ingestor->ingest($raw) !== null) {
                        ++$stored;
                    }
                } catch (\Throwable $e) {
                    ++$failed;
                    $lastFailure = $e::class . ': ' . $e->getMessage();
                    $this->logger->error('inbound.poll.message_failed', ['source' => $raw->source, 'sourceId' => $raw->sourceId, 'exceptionClass' => $e::class, 'message' => $e->getMessage()]);
                    $this->recoverEntityManager();

                    continue;
                }
                $this->source->acknowledge($raw);
            }

            $requeued = $this->requeueStale();

            $heartbeat = $this->heartbeats->current();
            if ($failed > 0 && $failed === $fetched) {
                $heartbeat->failed(sprintf('%d of %d messages could not be stored; last: %s', $failed, $fetched, $lastFailure));
                $this->em->flush();
                $io->error(sprintf('Inbound poll (%s): none of the %d messages could be stored.', $this->source->name(), $fetched));

                return Command::FAILURE;
            }
            if ($this->source->name() === NullInboundSource::NAME) {
                $this->logger->warning('inbound.poll.no_source');
                $io->warning('INBOUND_MAIL_DSN is empty: no mailbox was read.');
            } else {
                $heartbeat->succeeded($this->clock->now(), $fetched);
                if ($failed > 0) {
                    $heartbeat->failed(sprintf('%d of %d messages could not be stored; last: %s', $failed, $fetched, $lastFailure));
                }
            }
            $this->em->flush();
            $io->success(sprintf('Inbound poll (%s): %d fetched, %d new, %d failed, %d requeued.', $this->source->name(), $fetched, $stored, $failed, $requeued));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error('inbound.poll.failed', ['exceptionClass' => $e::class, 'message' => $e->getMessage()]);
            if ($this->em->isOpen()) {
                $this->heartbeats->current()->failed($e::class . ': ' . $e->getMessage());
                $this->em->flush();
            }
            $io->error('Inbound poll failed: ' . $e->getMessage());

            return Command::FAILURE;
        } finally {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
    }

    private function requeueStale(): int
    {
        $cutoff = $this->clock->now()->modify('-' . self::REQUEUE_AFTER_MINUTES . ' minutes');
        $requeued = 0;
        foreach ($this->mails->findUnprocessedBefore($cutoff, InboundEmailIngestor::MAX_REQUEUES) as $mail) {
            try {
                if ($this->ingestor->requeueIfUnprocessed($mail)) {
                    ++$requeued;
                }
            } catch (\Throwable $e) {
                $this->logger->error('inbound.poll.requeue_failed', ['inboundMailId' => $mail->getId(), 'exceptionClass' => $e::class, 'message' => $e->getMessage()]);
                $this->recoverEntityManager();
            }
        }

        return $requeued;
    }

    /**
     * Drops what the failed message left in the unit of work, so the next flush
     * does not retry it; a database error closes the manager, which is then reset.
     */
    private function recoverEntityManager(): void
    {
        if ($this->em->isOpen()) {
            $this->em->clear();

            return;
        }
        $this->registry->resetManager();
    }
}
