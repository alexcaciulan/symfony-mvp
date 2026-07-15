<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention cron for in-app notifications. Notifications are transient pointers
 * to case events (the durable record is the case, its deadlines and the audit
 * log), so read rows past a retention window and any row past a hard cap are
 * pruned to keep the table bounded and the inbox relevant.
 */
#[AsCommand(
    name: 'app:prune-notifications',
    description: 'Delete read notifications older than the retention window, and any notification older than the hard cap.',
)]
final class PruneNotificationsCommand extends Command
{
    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('read-days', null, InputOption::VALUE_REQUIRED, 'Delete read notifications older than this many days', '90')
            ->addOption('max-days', null, InputOption::VALUE_REQUIRED, 'Delete any notification older than this many days (safety cap)', '365')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report how many rows would be deleted without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Prune notifications');

        $readDays = (int) $input->getOption('read-days');
        $maxDays = (int) $input->getOption('max-days');
        if ($readDays < 1 || $maxDays < 1) {
            $io->error('read-days and max-days must be positive integers.');

            return Command::INVALID;
        }

        $now = new \DateTimeImmutable();
        $readCutoff = $now->modify("-{$readDays} days");
        $anyCutoff = $now->modify("-{$maxDays} days");

        $io->text(sprintf('Read cutoff: %s | Hard cap: %s', $readCutoff->format('Y-m-d'), $anyCutoff->format('Y-m-d')));

        if ($input->getOption('dry-run')) {
            $count = $this->repository->countObsolete($readCutoff, $anyCutoff);
            $io->note(sprintf('Dry run: %d notification(s) would be deleted.', $count));

            return Command::SUCCESS;
        }

        $deleted = $this->repository->pruneObsolete($readCutoff, $anyCutoff);
        $this->em->clear();

        $io->success(sprintf('Deleted %d obsolete notification(s).', $deleted));

        return Command::SUCCESS;
    }
}
