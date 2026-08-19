<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Deadline\DeadlineRealignmentChange;
use App\Service\Deadline\DeadlineRealignmentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off repair of the deadlines computed under the previous counting rules. Run by
 * hand at deploy time, deliberately not from the entrypoint or from a migration: it
 * rewrites dates a lawyer looks at, so somebody has to decide when it happens and read
 * what it reports.
 *
 * `--dry-run` prints exactly the same decisions without writing anything. Running it
 * twice writes nothing the second time: a row that has been realigned no longer matches
 * the previous rule, which is the only thing this command acts on.
 */
#[AsCommand(
    name: 'app:realign-deadlines',
    description: 'Realign deadlines computed under the previous counting rules, with an audit entry per row touched',
)]
final class RealignDeadlinesCommand extends Command
{
    public function __construct(
        private readonly DeadlineRealignmentService $realignmentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing anything');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text|json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $report = $this->realignmentService->realign($dryRun);

        if ($input->getOption('format') === 'json') {
            $output->writeln((string) json_encode($report->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title($dryRun ? 'Deadline realignment (dry run)' : 'Deadline realignment');

        $this->section($io, 'Moved', $report->ofAction(DeadlineRealignmentChange::ACTION_MOVED));
        $this->section($io, 'Removed', $report->ofAction(DeadlineRealignmentChange::ACTION_REMOVED));
        $this->section($io, 'Left untouched', $report->ofAction(DeadlineRealignmentChange::ACTION_SKIPPED));

        $io->section('Summary');
        $io->listing([
            sprintf('Moved: %d', $report->countOfAction(DeadlineRealignmentChange::ACTION_MOVED)),
            sprintf('Removed: %d', $report->countOfAction(DeadlineRealignmentChange::ACTION_REMOVED)),
            sprintf('Left untouched: %d', $report->countOfAction(DeadlineRealignmentChange::ACTION_SKIPPED)),
            sprintf('Already aligned: %d', $report->alreadyAligned),
        ]);

        if ($dryRun) {
            $io->note('Dry run: nothing was written.');

            return Command::SUCCESS;
        }

        $io->success('Realignment done.');

        return Command::SUCCESS;
    }

    /** @param list<DeadlineRealignmentChange> $changes */
    private function section(SymfonyStyle $io, string $title, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $io->section($title);
        $io->listing(array_map(static fn (DeadlineRealignmentChange $c): string => $c->describe(), $changes));
    }
}
