<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Billing\SubscriptionRenewalService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Daily cron command. Charges every due subscription renewal off-session on its
 * saved token and duns the failures (PAST_DUE + re-authorization email). No-op
 * unless PAYMENT_GATEWAY=netopia (the stub has no real recurring charges).
 */
#[AsCommand(
    name: 'app:subscriptions:renew',
    description: 'Charge due subscription renewals off-session and dun failures',
)]
final class RenewSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly SubscriptionRenewalService $renewalService,
        #[Autowire('%env(PAYMENT_GATEWAY)%')]
        private readonly string $paymentGatewayDefault,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('date', null, InputOption::VALUE_OPTIONAL, 'Cutoff date (Y-m-d) to renew subscriptions due on or before; defaults to now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('netopia' !== $this->paymentGatewayDefault) {
            $io->warning('PAYMENT_GATEWAY is not "netopia"; recurring charges are disabled. Nothing to do.');

            return Command::SUCCESS;
        }

        $dateOption = $input->getOption('date');
        if (null !== $dateOption) {
            $on = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $dateOption);
            if (false === $on) {
                $io->error('Invalid --date; expected Y-m-d.');

                return Command::INVALID;
            }
            $on = $on->setTime(23, 59, 59);
        } else {
            $on = new \DateTimeImmutable();
        }

        $io->title('Subscription renewals due by ' . $on->format('Y-m-d H:i:s'));

        $summary = $this->renewalService->renewDue($on);

        $io->success(sprintf(
            'Processed %d renewals: charged=%d, past_due=%d, reauth=%d.',
            $summary['processed'],
            $summary['charged'],
            $summary['past_due'],
            $summary['reauth'],
        ));

        return Command::SUCCESS;
    }
}
