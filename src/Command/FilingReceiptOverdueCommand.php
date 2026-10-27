<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Inbound\ReceiptOverdueChecker;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:filing:receipt-overdue', description: 'Flag transmitted petitions still without a court receipt')]
final class FilingReceiptOverdueCommand extends Command
{
    public function __construct(
        private readonly ReceiptOverdueChecker $checker,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $flagged = $this->checker->run($this->clock->now());
        (new SymfonyStyle($input, $output))->success(sprintf('Receipt overdue check: %d submission(s) flagged.', count($flagged)));

        return Command::SUCCESS;
    }
}
