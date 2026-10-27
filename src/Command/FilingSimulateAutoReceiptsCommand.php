<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\LegalCase;
use App\Repository\AuditLogRepository;
use App\Repository\FilingSubmissionRepository;
use App\Service\AuditLogService;
use App\Service\Inbound\CourtReplySimulator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Demo stand-in for the court: each recent submission whose petition left at least
 * --min-age seconds ago gets one simulated receipt in Mailpit, read by the next
 * poll. The audit entry marks it answered, so a failed poll never doubles it.
 */
#[AsCommand(name: 'app:filing:simulate-auto-receipts', description: 'Demo only: write a simulated court receipt for every recently transmitted submission still awaiting one')]
final class FilingSimulateAutoReceiptsCommand extends Command
{
    public const AUDIT_ACTION = 'filing_receipt_simulated';

    public function __construct(
        private readonly CourtReplySimulator $simulator,
        private readonly FilingSubmissionRepository $submissions,
        private readonly AuditLogRepository $auditLogs,
        private readonly AuditLogService $auditLogService,
        private readonly EntityManagerInterface $em,
        private readonly bool $inboundAutoReceipt,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min-age', null, InputOption::VALUE_REQUIRED, 'Seconds since the petition was sent before it is answered', '60')
            ->addOption('max-age-days', null, InputOption::VALUE_REQUIRED, 'Older submissions are left alone', '7');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $refusal = $this->inboundAutoReceipt ? $this->simulator->refusal() : 'INBOUND_AUTO_RECEIPT is off.';
        if ($refusal !== null) {
            $io->note('Skipped: ' . $refusal);

            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable();
        $to = $now->modify(sprintf('-%d seconds', max(0, (int) $input->getOption('min-age'))));
        $from = $now->modify(sprintf('-%d days', max(1, (int) $input->getOption('max-age-days'))));

        $answered = 0;
        $failed = 0;
        foreach ($this->submissions->findAwaitingReceiptSentBetween($from, $to) as $submission) {
            if ($this->auditLogs->hasActionForSubmission($submission, self::AUDIT_ACTION)) {
                continue;
            }
            try {
                $this->simulator->simulate($submission, 'receipt');
            } catch (\Throwable $e) {
                ++$failed;
                $io->warning(sprintf('Submission %d: %s', (int) $submission->getId(), $e->getMessage()));

                continue;
            }
            $this->auditLogService->log(
                action: self::AUDIT_ACTION,
                entityType: LegalCase::class,
                entityId: (string) $submission->getLegalCase()->getId(),
                newData: ['submissionId' => $submission->getId()],
                category: AuditLogService::CATEGORY_FILING_SUBMISSION,
            );
            $this->em->flush();
            ++$answered;
        }
        $io->success(sprintf('Simulated %d court receipt(s).', $answered));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
