<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\NotificationType;
use App\Repository\InboundMailRepository;
use App\Repository\InboundPollHeartbeatRepository;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationDispatch;
use App\Service\Notification\NotificationDispatcherInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A court message missed because the poll stopped would go unseen, so a stale
 * last success alerts every admin (once a day) and fails the run for the monitor.
 * So does a stored message whose processing has not completed for a long time
 * (its queue message lost or failing every time).
 */
#[AsCommand(name: 'app:filing:inbound-heartbeat-check', description: 'Alert admins when the inbound mailbox poll has not succeeded recently')]
final class InboundHeartbeatCheckCommand extends Command
{
    private const UNPROCESSED_ALERT_MINUTES = 30;

    public function __construct(
        private readonly InboundPollHeartbeatRepository $heartbeats,
        private readonly InboundMailRepository $mails,
        private readonly UserRepository $users,
        private readonly NotificationDispatcherInterface $dispatcher,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $inboundHeartbeatMaxAgeMinutes,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();
        $unprocessed = $this->mails->countUnprocessedBefore($now->modify('-' . self::UNPROCESSED_ALERT_MINUTES . ' minutes'));
        if ($unprocessed > 0) {
            $this->logger->critical('inbound.unprocessed', ['count' => $unprocessed]);
            $this->alertAdmins(
                NotificationType::INBOUND_UNPROCESSED,
                'notification.inbound_unprocessed',
                ['%count%' => (string) $unprocessed, '%minutes%' => (string) self::UNPROCESSED_ALERT_MINUTES],
                'inbound_unprocessed',
                $now,
            );
            $io->error(sprintf('%d inbound messages stored more than %d minutes ago are still unprocessed.', $unprocessed, self::UNPROCESSED_ALERT_MINUTES));
        }

        $heartbeat = $this->heartbeats->findOneBy([], ['id' => 'ASC']);
        $lastSuccess = $heartbeat?->getLastSuccessAt();
        $limit = $now->modify('-' . $this->inboundHeartbeatMaxAgeMinutes . ' minutes');

        if ($lastSuccess !== null && $lastSuccess >= $limit) {
            $io->success('Inbound poll last succeeded at ' . $lastSuccess->format(\DateTimeInterface::ATOM) . '.');

            return $unprocessed > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $since = $lastSuccess?->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i') ?? '-';
        $this->logger->critical('inbound.poll.stale', ['lastSuccessAt' => $lastSuccess?->format(\DateTimeInterface::ATOM), 'lastError' => $heartbeat?->getLastError()]);
        $this->alertAdmins(
            NotificationType::INBOUND_POLL_STALE,
            'notification.inbound_poll_stale',
            ['%since%' => $since, '%minutes%' => (string) $this->inboundHeartbeatMaxAgeMinutes],
            'inbound_poll_stale',
            $now,
        );
        $io->error('Inbound poll has not succeeded since ' . ($lastSuccess?->format(\DateTimeInterface::ATOM) ?? 'ever') . '.');

        return Command::FAILURE;
    }

    /** @param array<string, string> $params */
    private function alertAdmins(NotificationType $type, string $key, array $params, string $dedup, \DateTimeImmutable $now): void
    {
        foreach ($this->users->findAdmins() as $admin) {
            $this->dispatcher->dispatch(new NotificationDispatch(
                user: $admin,
                legalCase: null,
                type: $type,
                title: $this->translator->trans($key . '.title'),
                message: $this->translator->trans($key . '.message', $params),
                resourceLink: null,
                variant: 'error',
                emailSubject: null,
                emailTemplate: null,
                dedupKey: sprintf('%s:%d:%s', $dedup, (int) $admin->getId(), $now->format('Y-m-d')),
            ));
        }
    }
}
