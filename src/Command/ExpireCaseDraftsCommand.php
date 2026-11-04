<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CaseDraftRepository;
use App\Repository\DocumentRepository;
use App\Service\Document\DocumentUploadService;
use App\Service\Wizard\CaseDraftDiscarder;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention cron for unfinished wizard runs. A draft nobody touched for the
 * window goes with its documents; an upload that belongs to no case and no
 * draft (left behind before drafts existed, or by a failed save) goes once it
 * is older than the same window, unless a worker is still reading it.
 */
#[AsCommand(
    name: 'app:drafts:expire',
    description: 'Delete wizard drafts inactive for longer than the window, with their documents, and sweep old orphan uploads',
)]
final class ExpireCaseDraftsCommand extends Command
{
    public function __construct(
        private readonly CaseDraftRepository $drafts,
        private readonly DocumentRepository $documents,
        private readonly CaseDraftDiscarder $discarder,
        private readonly DocumentUploadService $uploads,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete drafts and orphan uploads older than this many days', '30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Expire wizard drafts');

        $days = (int) $input->getOption('days');
        if ($days < 1) {
            $io->error('days must be a positive integer.');

            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', $days));
        $io->text(sprintf('Cutoff: %s%s', $cutoff->format('Y-m-d H:i'), $dryRun ? ' (dry run)' : ''));

        $io->section('Inactive drafts');
        $expired = $this->drafts->findInactiveSince($cutoff);
        $documentsWithDrafts = 0;
        foreach ($expired as $draft) {
            // Read before the delete: a removed entity no longer carries its id.
            $draftId = $draft->getId();
            $count = $dryRun ? count($this->discarder->documentsOf($draft)) : $this->discarder->discard($draft, null, CaseDraftDiscarder::REASON_EXPIRED);
            $documentsWithDrafts += $count;
            $io->text(sprintf('%s draft #%d (user #%d, last activity %s, %d document(s))', $dryRun ? 'Would delete' : 'Deleted', $draftId, $draft->getUser()->getId(), $draft->getLastActivityAt()->format('Y-m-d'), $count));
        }

        $io->section('Orphan uploads');
        $orphans = $this->documents->findOrphansOlderThan($cutoff);
        foreach ($orphans as $document) {
            // Read before the delete: a removed entity no longer carries its id.
            $line = sprintf('%s document #%d (uploaded %s)', $dryRun ? 'Would delete' : 'Deleted', $document->getId(), $document->getCreatedAt()->format('Y-m-d'));
            if (!$dryRun) {
                $this->uploads->delete($document, null);
            }
            $io->text($line);
        }

        $summary = sprintf('%d draft(s) with %d document(s), %d orphan upload(s).', count($expired), $documentsWithDrafts, count($orphans));
        if ($dryRun) {
            $io->note('Dry run: ' . $summary);
        } else {
            $io->success('Deleted ' . $summary);
        }

        return Command::SUCCESS;
    }
}
