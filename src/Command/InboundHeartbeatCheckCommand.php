<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\NotificationType;
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
 */
#[AsCommand(name: 'app:filing:inbound-heartbeat-check', description: 'Alert admins when the inbound mailbox poll has not succeeded recently')]
final class InboundHeartbeatCheckCommand extends Command
{
    public function __construct(
        private readonly InboundPollHeartbeatRepository $heartbeats,
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
        $heartbeat = $this->heartbeats->findOneBy([], ['id' => 'ASC']);
        $lastSuccess = $heartbeat?->getLastSuccessAt();
        $limit = $now->modify('-' . $this->inboundHeartbeatMaxAgeMinutes . ' minutes');

        if ($lastSuccess !== null && $lastSuccess >= $limit) {
            $io->success('Inbound poll last succeeded at ' . $lastSuccess->format(\DateTimeInterface::ATOM) . '.');

            return Command::SUCCESS;
        }

        $since = $lastSuccess?->setTimezone(new \DateTimeZone('Europe/Bucharest'))->format('d.m.Y H:i') ?? '-';
        $this->logger->critical('inbound.poll.stale', ['lastSuccessAt' => $lastSuccess?->format(\DateTimeInterface::ATOM), 'lastError' => $heartbeat?->getLastError()]);
        foreach ($this->users->findAdmins() as $admin) {
            $this->dispatcher->dispatch(new NotificationDispatch(
                user: $admin,
                legalCase: null,
                type: NotificationType::INBOUND_POLL_STALE,
                title: $this->translator->trans('notification.inbound_poll_stale.title'),
                message: $this->translator->trans('notification.inbound_poll_stale.message', ['%since%' => $since, '%minutes%' => (string) $this->inboundHeartbeatMaxAgeMinutes]),
                resourceLink: null,
                variant: 'error',
                emailSubject: null,
                emailTemplate: null,
                dedupKey: sprintf('inbound_poll_stale:%d:%s', (int) $admin->getId(), $now->format('Y-m-d')),
            ));
        }
        $io->error('Inbound poll has not succeeded since ' . ($lastSuccess?->format(\DateTimeInterface::ATOM) ?? 'ever') . '.');

        return Command::FAILURE;
    }
}
