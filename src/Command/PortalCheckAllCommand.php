<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\LegalCase;
use App\Message\CheckCasePortalMessage;
use App\Repository\LegalCaseRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Daily cron command. Dispatches one {@see CheckCasePortalMessage} per eligible
 * case, staggered with a DelayStamp (2-5s apart, to rate-limit the external
 * portal), processed asynchronously by a worker with retry on transient failure.
 */
#[AsCommand(
    name: 'app:portal-check-all',
    description: 'Dispatch async portal.just.ro checks for all active cases',
)]
final class PortalCheckAllCommand extends Command
{
    private const MIN_DELAY_MS = 2000;
    private const MAX_DELAY_MS = 5000;

    public function __construct(
        private readonly LegalCaseRepository $caseRepository,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('case-id', null, InputOption::VALUE_OPTIONAL, 'Dispatch a single case by ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $specificCaseId = $input->getOption('case-id');

        if ($specificCaseId !== null) {
            $case = $this->caseRepository->find((int) $specificCaseId);
            $cases = $case instanceof LegalCase && !$case->isDeleted() ? [$case] : [];
        } else {
            $cases = $this->caseRepository->findActiveForMonitoring();
        }

        $cumulativeDelay = 0;
        $dispatched = 0;
        foreach ($cases as $case) {
            $this->bus->dispatch(
                new CheckCasePortalMessage((int) $case->getId()),
                [new DelayStamp($cumulativeDelay)],
            );
            $cumulativeDelay += random_int(self::MIN_DELAY_MS, self::MAX_DELAY_MS);
            ++$dispatched;
        }

        $io->success(sprintf('Dispatched %d case(s) for async portal check.', $dispatched));

        return Command::SUCCESS;
    }
}
