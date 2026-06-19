<?php

namespace App\Command;

use App\Entity\City;
use App\Entity\County;
use App\Enum\UatType;
use App\Repository\CityRepository;
use App\Repository\CountyRepository;
use App\Service\Court\LocalityNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:import-cities',
    description: 'Import Romanian counties + UAT-level cities (municipalities, towns, communes, Bucharest sectors) from data/cities.json',
)]
class ImportCitiesCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private CountyRepository $countyRepository,
        private CityRepository $cityRepository,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'update',
            null,
            InputOption::VALUE_NONE,
            'Update existing cities (refresh the UAT type) instead of skipping them.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jsonPath = $this->projectDir . '/data/cities.json';
        $updateMode = (bool) $input->getOption('update');

        if (!file_exists($jsonPath)) {
            $io->error('File not found: ' . $jsonPath);
            return Command::FAILURE;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            $io->error('Invalid JSON in ' . $jsonPath);
            return Command::FAILURE;
        }

        $countyCache = [];
        $countiesCreated = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($data as $entry) {
            $countyName = (string) ($entry['county'] ?? '');
            $cityName = (string) ($entry['name'] ?? '');
            if ($countyName === '' || $cityName === '') {
                continue;
            }

            $countyNormalized = LocalityNormalizer::normalize($countyName);
            if ($countyNormalized === null) {
                continue;
            }

            if (!isset($countyCache[$countyNormalized])) {
                $county = $this->countyRepository->findOneByNormalizedName($countyNormalized);
                if ($county === null) {
                    $county = new County();
                    $county->setName($countyName);
                    $county->setNormalizedName($countyNormalized);
                    $this->em->persist($county);
                    $countiesCreated++;
                }
                $countyCache[$countyNormalized] = $county;
            }
            $county = $countyCache[$countyNormalized];

            $cityNormalized = LocalityNormalizer::normalize($cityName);
            if ($cityNormalized === null) {
                continue;
            }

            $type = $this->resolveType($entry['type'] ?? null);

            $existing = $this->cityRepository->findOneByCountyAndNormalizedName($county, $cityNormalized);
            if ($existing !== null) {
                if (!$updateMode) {
                    $skipped++;
                    continue;
                }
                $existing->setType($type);
                $updated++;
                continue;
            }

            $city = new City();
            $city->setCounty($county);
            $city->setName($cityName);
            $city->setNormalizedName($cityNormalized);
            $city->setType($type);
            $this->em->persist($city);
            $created++;
        }

        $this->em->flush();

        $io->success(sprintf(
            'Import finished: %d counties created, %d cities created, %d updated, %d skipped.',
            $countiesCreated,
            $created,
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    private function resolveType(mixed $value): ?UatType
    {
        if (!is_string($value)) {
            return null;
        }

        return UatType::tryFrom($value);
    }
}
