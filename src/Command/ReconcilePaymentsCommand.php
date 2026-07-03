<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Billing\PaymentReconciliationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cron command. Re-queries the gateway for stale-pending invoices whose IPN was
 * lost and settles the ones the gateway reports paid. No-op unless
 * PAYMENT_GATEWAY=netopia.
 */
#[AsCommand(
    name: 'app:payments:reconcile',
    description: 'Reconcile stale-pending invoices against the gateway status (lost-IPN backstop)',
)]
final class ReconcilePaymentsCommand extends Command
{
    private const DEFAULT_MIN_AGE_MINUTES = 15;

    public function __construct(
        private readonly PaymentReconciliationService $reconciliation,
        #[Autowire('%env(PAYMENT_GATEWAY)%')]
        private readonly string $paymentGatewayDefault,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('min-age', null, InputOption::VALUE_OPTIONAL, 'Only reconcile invoices at least this many minutes old', (string) self::DEFAULT_MIN_AGE_MINUTES);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('netopia' !== $this->paymentGatewayDefault) {
            $io->warning('PAYMENT_GATEWAY is not "netopia"; reconciliation is disabled. Nothing to do.');

            return Command::SUCCESS;
        }

        $minAge = max(0, (int) $input->getOption('min-age'));
        $before = (new \DateTimeImmutable())->modify(sprintf('-%d minutes', $minAge));

        $io->title('Reconcile stale-pending payments older than ' . $before->format('Y-m-d H:i:s'));

        $summary = $this->reconciliation->reconcile($before);

        $io->success(sprintf(
            'Checked %d, settled %d, still pending %d, errors %d.',
            $summary['checked'],
            $summary['settled'],
            $summary['still_pending'],
            $summary['errors'],
        ));

        return Command::SUCCESS;
    }
}
