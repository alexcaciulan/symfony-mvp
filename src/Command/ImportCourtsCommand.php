<?php

namespace App\Command;

use App\Entity\Court;
use App\Enum\CourtType;
use App\Repository\CourtRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:import-courts',
    description: 'Import Romanian courts (judecătorii + tribunale) from data/courts.json',
)]
class ImportCourtsCommand extends Command
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
        $jsonPath = $this->projectDir . '/data/courts.json';

        if (!file_exists($jsonPath)) {
            $io->error('File not found: ' . $jsonPath);
            return Command::FAILURE;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            $io->error('Invalid JSON in ' . $jsonPath);
            return Command::FAILURE;
        }

        $created = 0;
        $skipped = 0;

        foreach ($data as $entry) {
            $existing = $this->courtRepository->findOneBy(['name' => $entry['name']]);
            if ($existing !== null) {
                $skipped++;
                continue;
            }

            $court = new Court();
            $court->setName($entry['name']);
            $court->setCounty($entry['county']);
            $court->setType(CourtType::from($entry['type']));
            $court->setActive(true);

            $this->em->persist($court);
            $created++;
        }

        $this->em->flush();

        $io->success(sprintf('Import finished: %d created, %d skipped (already exist).', $created, $skipped));

        return Command::SUCCESS;
    }
}
