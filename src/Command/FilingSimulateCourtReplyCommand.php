<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\FilingSubmission;
use App\Repository\FilingSubmissionRepository;
use App\Service\Inbound\CourtReplySimulator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:filing:simulate-court-reply', description: 'Dev only: write a simulated court answer or delivery report for a submission to Mailpit')]
final class FilingSimulateCourtReplyCommand extends Command
{
    public function __construct(
        private readonly CourtReplySimulator $simulator,
        private readonly FilingSubmissionRepository $submissions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('submission', null, InputOption::VALUE_REQUIRED, 'Filing submission id')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'One of: ' . implode(', ', CourtReplySimulator::KINDS))
            ->addOption('part', null, InputOption::VALUE_REQUIRED, 'Email sequence the answer is about', '1')
            ->addOption('forged-auth', null, InputOption::VALUE_NONE, 'Write the authentication header under a foreign authserv-id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $refusal = $this->simulator->refusal();
        if ($refusal !== null) {
            $io->error('Refused: ' . $refusal);

            return Command::FAILURE;
        }
        $submission = $this->submissions->find((int) $input->getOption('submission'));
        if (!$submission instanceof FilingSubmission) {
            $io->error('Submission not found.');

            return Command::INVALID;
        }
        $kind = (string) $input->getOption('kind');
        if (!in_array($kind, CourtReplySimulator::KINDS, true)) {
            $io->error('Unknown kind. Use one of: ' . implode(', ', CourtReplySimulator::KINDS));

            return Command::INVALID;
        }
        try {
            $to = $this->simulator->simulate($submission, $kind, (int) $input->getOption('part'), (bool) $input->getOption('forged-auth'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $io->success(sprintf('Simulated %s for submission %d written to %s. Run app:filing:poll-inbound to read it.', $kind, (int) $submission->getId(), $to));

        return Command::SUCCESS;
    }
}
