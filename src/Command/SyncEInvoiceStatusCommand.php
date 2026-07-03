<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\FiscalInvoiceRepository;
use App\Service\Billing\FiscalInvoiceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Polls the provider for e-Factura (SPV) status of in-transit invoices and
 * reconciles paid-but-unissued mirrors (provider was down at payment time).
 * Providers give no reliable SPV webhook, so this runs on a cron.
 */
#[AsCommand(
    name: 'app:einvoice:sync-status',
    description: 'Poll SPV status for in-transit fiscal invoices and reissue unissued ones.',
)]
class SyncEInvoiceStatusCommand extends Command
{
    public function __construct(
        private readonly FiscalInvoiceRepository $fiscalInvoices,
        private readonly FiscalInvoiceService $fiscalInvoiceService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $synced = 0;
        foreach ($this->fiscalInvoices->findInTransit() as $invoice) {
            try {
                $this->fiscalInvoiceService->syncEInvoiceStatus($invoice);
                ++$synced;
            } catch (\Throwable $e) {
                $io->warning(sprintf('Status sync failed for #%d: [%s] %s', $invoice->getId(), $e::class, $e->getMessage()));
            }
        }

        $reissued = 0;
        foreach ($this->fiscalInvoices->findUnissued() as $invoice) {
            $source = $invoice->getInvoice();
            if (null === $source) {
                continue;
            }
            try {
                $this->fiscalInvoiceService->issueForPaidInvoice($source);
                ++$reissued;
            } catch (\Throwable $e) {
                $io->warning(sprintf('Reissue failed for #%d: [%s] %s', $invoice->getId(), $e::class, $e->getMessage()));
            }
        }

        $io->success(sprintf('Synced %d, reissued %d.', $synced, $reissued));

        return Command::SUCCESS;
    }
}
