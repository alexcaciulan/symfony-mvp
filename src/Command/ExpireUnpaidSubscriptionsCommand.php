<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Billing\SubscriptionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Daily cron command. Releases subscriptions that went ACTIVE at checkout but
 * were never paid for, so an abandoned payment stops locking the account out of
 * every other plan.
 */
#[AsCommand(
    name: 'app:subscriptions:expire-unpaid',
    description: 'Release never-paid subscriptions and cancel their open invoices',
)]
final class ExpireUnpaidSubscriptionsCommand extends Command
{
    private const DEFAULT_GRACE_DAYS = 3;

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'grace-days',
            null,
            InputOption::VALUE_OPTIONAL,
            'Days a subscription may stay unpaid before it is released',
            (string) self::DEFAULT_GRACE_DAYS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $graceDays = (int) $input->getOption('grace-days');
        if ($graceDays < 1) {
            $io->error('Invalid --grace-days; expected a positive integer.');

            return Command::INVALID;
        }

        $before = (new \DateTimeImmutable())->modify(sprintf('-%d days', $graceDays));
        $io->title('Expire unpaid subscriptions created before ' . $before->format('Y-m-d H:i'));

        $released = $this->subscriptionService->expireAbandonedCheckouts($before);

        if (0 === $released) {
            $io->success('No unpaid subscriptions to release.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Released %d unpaid subscription(s).', $released));

        return Command::SUCCESS;
    }
}
