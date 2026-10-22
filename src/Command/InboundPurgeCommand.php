<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\InboundMail;
use App\Repository\InboundMailRepository;
use App\Service\AuditLogService;
use App\Service\Inbound\InboundRawStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes unattributed and spam messages once their purge date passed, with their
 * raw files. A message tied to a submission or a case is never deleted here: its
 * retention waits for the final rule.
 */
#[AsCommand(name: 'app:filing:inbound-purge', description: 'Delete unattributed and spam inbound messages past their retention')]
final class InboundPurgeCommand extends Command
{
    public function __construct(
        private readonly InboundMailRepository $mails,
        private readonly InboundRawStorage $storage,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogService $auditLogService,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted without deleting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $purgeable = $this->mails->findPurgeable($this->clock->now());
        if ($dryRun) {
            $io->success(sprintf('Dry run: %d inbound message(s) would be deleted.', count($purgeable)));

            return Command::SUCCESS;
        }

        $paths = [];
        $ids = [];
        foreach ($purgeable as $mail) {
            $paths[] = $mail->getRawPath();
            $ids[] = $mail->getId();
            $this->em->remove($mail);
        }
        $this->em->flush();
        foreach (array_unique($paths) as $path) {
            // The same bytes may still back another row (one file per hash).
            if ($this->mails->count(['rawPath' => $path]) === 0) {
                $this->storage->delete($path);
            }
        }
        foreach ($ids as $id) {
            $this->auditLogService->log(action: 'inbound_mail_purged', entityType: InboundMail::class, entityId: (string) $id);
        }
        $this->em->flush();
        $io->success(sprintf('%d inbound message(s) deleted.', count($ids)));

        return Command::SUCCESS;
    }
}
