<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\LegalCaseRepository;
use App\Service\Portal\PortalCaseDiscoveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Daily cron command: looks for each filed case on portal.just.ro until its
 * number is known, and notifies the lawyer when one is found. Runs the searches
 * one after the other, a little apart, to stay gentle with the public portal.
 */
#[AsCommand(
    name: 'app:portal-discover-cases',
    description: 'Look up filed cases on portal.just.ro and notify the lawyer when one is found',
)]
final class PortalDiscoverCasesCommand extends Command
{
    private const PAUSE_MICROSECONDS = 2_000_000;

    public function __construct(
        private readonly LegalCaseRepository $caseRepository,
        private readonly PortalCaseDiscoveryService $discovery,
        private readonly int $pauseMicroseconds = self::PAUSE_MICROSECONDS,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cases = $this->caseRepository->findAwaitingPortalDiscovery();

        $found = 0;
        foreach ($cases as $index => $case) {
            if ($index > 0 && $this->pauseMicroseconds > 0) {
                usleep($this->pauseMicroseconds);
            }
            $number = $this->discovery->discover($case);
            if ($number !== null) {
                ++$found;
                $io->writeln(sprintf('Case #%d: found %s on the portal', (int) $case->getId(), $number));
            }
        }

        $io->success(sprintf('Searched %d filed case(s), found %d on the portal.', count($cases), $found));

        return Command::SUCCESS;
    }
}
