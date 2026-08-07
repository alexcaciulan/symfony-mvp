<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\StampDuty\StampDutyReminderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:check-stamp-duty',
    description: 'Remind lawyers about filed petitions whose stamp duty is still unpaid',
)]
final class CheckStampDutyCommand extends Command
{
    public function __construct(
        private readonly StampDutyReminderService $reminders,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('date', null, InputOption::VALUE_OPTIONAL, 'Run as if today were this date (Y-m-d)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dateOption = $input->getOption('date');
        $today = $dateOption !== null
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $dateOption)
            : new \DateTimeImmutable('today');

        if ($today === false) {
            $io->error('Invalid date, expected Y-m-d.');

            return Command::FAILURE;
        }

        $io->title('Stamp duty check ' . $today->format('Y-m-d'));

        $sent = $this->reminders->run($today);

        $io->success(sprintf('%d reminder(s) sent.', $sent));

        return Command::SUCCESS;
    }
}
