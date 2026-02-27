<?php

namespace App\Command;

use App\Repository\LegalCaseRepository;
use App\Service\Portal\CaseMonitoringService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:monitor-court-cases',
    description: 'Query portal.just.ro for updates on active court cases',
)]
class MonitorCourtCasesCommand extends Command
{
    private const MONITORED_STATUSES = [
        'submitted_to_court',
        'under_review',
        'additional_info_requested',
    ];

    public function __construct(
        private LegalCaseRepository $caseRepository,
        private CaseMonitoringService $monitoringService,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('case-id', null, InputOption::VALUE_OPTIONAL, 'Monitor a specific case by ID')
            ->addOption('delay', null, InputOption::VALUE_OPTIONAL, 'Delay in ms between portal queries', '2000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $delay = (int) $input->getOption('delay');
        $specificCaseId = $input->getOption('case-id');

        if ($specificCaseId !== null) {
            $case = $this->caseRepository->find((int) $specificCaseId);
            $cases = $case !== null ? [$case] : [];
        } else {
            $cases = $this->caseRepository->findMonitorableCases(self::MONITORED_STATUSES);
        }

        $io->info(sprintf('Found %d case(s) to monitor.', count($cases)));

        $totalNew = 0;
        $errors = 0;
        $isFirst = true;

        foreach ($cases as $case) {
            if ($case->getCaseNumber() === null) {
                $io->note(sprintf('Case #%d has no case number, skipping.', $case->getId()));
                continue;
            }

            // Rate limiting between requests
            if (!$isFirst && $delay > 0) {
                usleep($delay * 1000);
            }
            $isFirst = false;

            try {
                $newCount = $this->monitoringService->monitorCase($case);
                $totalNew += $newCount;

                if ($newCount > 0) {
                    $io->success(sprintf(
                        'Case #%d (%s): %d new event(s) detected.',
                        $case->getId(),
                        $case->getCaseNumber(),
                        $newCount,
                    ));
                } else {
                    $io->text(sprintf(
                        'Case #%d (%s): no new events.',
                        $case->getId(),
                        $case->getCaseNumber(),
                    ));
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('Error monitoring case #{id}: {error}', [
                    'id' => $case->getId(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $io->error(sprintf(
                    'Case #%d (%s): ERROR - %s',
                    $case->getId(),
                    $case->getCaseNumber(),
                    $e->getMessage(),
                ));
            }
        }

        $io->info(sprintf(
            'Monitoring complete: %d case(s) checked, %d new event(s), %d error(s).',
            count($cases),
            $totalNew,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
