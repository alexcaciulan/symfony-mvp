<?php

namespace App\Command;

use App\Repository\CourtRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:import-court-portal-codes',
    description: 'Import portal.just.ro institution codes for courts from data/court_portal_codes.json',
)]
class ImportCourtPortalCodesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private CourtRepository $courtRepository,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jsonPath = $this->projectDir . '/data/court_portal_codes.json';

        if (!file_exists($jsonPath)) {
            $io->error('File not found: ' . $jsonPath);

            return Command::FAILURE;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            $io->error('Invalid JSON in ' . $jsonPath);

            return Command::FAILURE;
        }

        $updated = 0;
        $notFound = 0;

        foreach ($data as $courtName => $portalCode) {
            $court = $this->courtRepository->findOneBy(['name' => $courtName]);
            if ($court === null) {
                $io->note(sprintf('Court not found: "%s"', $courtName));
                $notFound++;
                continue;
            }

            $court->setPortalCode($portalCode);
            $updated++;
        }

        $this->em->flush();

        $io->success(sprintf(
            'Import finished: %d court(s) updated, %d not found.',
            $updated,
            $notFound,
        ));

        return Command::SUCCESS;
    }
}
