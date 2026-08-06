<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Deadline\BlockedCaseAlertService;
use App\Service\Deadline\CaseAutoFinalizer;
use App\Service\Deadline\DeadlineAlertService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Daily cron command:
 *  1. Emits alerts for deadlines approaching expiry and expired ones (DeadlineAlertService).
 *  2. Auto-transitions to DEFINITIVA the cases whose annulment-request window has
 *     lapsed, and closes the annulment terms that have run (CaseAutoFinalizer).
 *  3. Raises alerts for cases blocked on an act with no term running on them
 *     (BlockedCaseAlertService), throttled to one message per case per week.
 *
 * `--format=json` for CI / monitoring integration; defaults to text for cron logs.
 */
#[AsCommand(
    name: 'app:check-deadlines',
    description: 'Emit deadline alerts (7/3/1/expired) and auto-finalize cases past the annulment window',
)]
final class CheckDeadlinesCommand extends Command
{
    public function __construct(
        private readonly DeadlineAlertService $alertService,
        private readonly CaseAutoFinalizer $autoFinalizer,
        private readonly BlockedCaseAlertService $blockedCaseAlertService,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text|json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->now();

        $alerts = $this->alertService->processAlerts($now);
        $autoFinal = $this->autoFinalizer->process($now);
        $blocked = $this->blockedCaseAlertService->process($now);

        if ($input->getOption('format') === 'json') {
            $output->writeln((string) json_encode([
                'now' => $now->format(\DateTimeInterface::ATOM),
                'alerts' => $alerts->toArray(),
                'autoFinalize' => $autoFinal->toArray(),
                'blockedCases' => $blocked->toArray(),
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Deadline check ' . $now->format('Y-m-d'));
        $io->section('Deadline alerts');
        $io->listing([
            sprintf('Long range (limitation terms): %d', $alerts->sentLongRange),
            sprintf('7 days: %d', $alerts->sent7),
            sprintf('3 days: %d', $alerts->sent3),
            sprintf('1 day: %d', $alerts->sent1),
            sprintf('Expired: %d', $alerts->expired),
        ]);
        $io->section('Auto-finalization');
        $io->listing([
            sprintf('Marked DEFINITIVA: %d', $autoFinal->finalized),
            sprintf('Missing communication date (lawyer alerted): %d', $autoFinal->missingCommunicationDate),
            sprintf('Not yet due: %d', $autoFinal->notYetDue),
            sprintf('Annulment terms closed as lapsed: %d', $autoFinal->appealTermsClosed),
        ]);
        $io->section('Blocked cases');
        $io->listing([
            sprintf('Stamp duty due after case number: %d', $blocked->stampDutyDue),
            sprintf('Regularization notice date missing: %d', $blocked->regularizationNoticeDateMissing),
        ]);
        $io->success(sprintf(
            '%d alerts emitted, %d cases finalized, %d blocked cases raised.',
            $alerts->total(),
            $autoFinal->finalized,
            $blocked->total(),
        ));

        return Command::SUCCESS;
    }
}
