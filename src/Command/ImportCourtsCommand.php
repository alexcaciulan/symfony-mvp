<?php

namespace App\Command;

use App\Entity\County;
use App\Entity\Court;
use App\Enum\CourtType;
use App\Repository\CityRepository;
use App\Repository\CountyRepository;
use App\Repository\CourtRepository;
use App\Service\Court\CourtNameIndex;
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
    name: 'app:import-courts',
    description: 'Import Romanian courts (judecătorii + tribunale) from data/courts.json',
)]
class ImportCourtsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private CourtRepository $courtRepository,
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
            'Update existing courts (refresh covered cities / address / email / phone) instead of skipping them.',
        );
        $this->addOption(
            'strict',
            null,
            InputOption::VALUE_NONE,
            'Also fail when the database holds courts the data file does not mention.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jsonPath = $this->projectDir . '/data/courts.json';
        $updateMode = (bool) $input->getOption('update');
        $strict = (bool) $input->getOption('strict');

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
        $updated = 0;
        $skipped = 0;
        $renamed = [];
        $ambiguous = [];
        $missingCounties = [];

        $index = new CourtNameIndex($this->courtRepository->findAll());
        $matchedNames = [];

        foreach ($data as $entry) {
            $countyNormalized = LocalityNormalizer::normalize(
                isset($entry['county']) ? (string) $entry['county'] : null,
            );
            $county = $countyNormalized !== null
                ? $this->countyRepository->findOneByNormalizedName($countyNormalized)
                : null;
            if ($county === null) {
                $io->warning(sprintf(
                    'County "%s" not found, skipping court "%s". Run app:import-cities first.',
                    $entry['county'] ?? '',
                    $entry['name'] ?? '',
                ));
                $missingCounties[] = (string) ($entry['name'] ?? '');
                $skipped++;
                continue;
            }

            $name = (string) $entry['name'];

            // A name that resolves to several courts already means duplicates exist;
            // picking one at random would spread the damage.
            if ($index->isAmbiguous($name)) {
                $io->warning(sprintf('"%s" matches several existing courts. Resolve the duplicate manually.', $name));
                $ambiguous[] = $name;
                $skipped++;
                continue;
            }

            $existing = $index->find($name);

            if ($existing !== null) {
                if (!$updateMode) {
                    // Key on the stored name: without --update it keeps its own spelling.
                    $matchedNames[$existing->getName()] = true;
                    $skipped++;
                    continue;
                }
                // Matched through normalization: adopt the spelling from the data
                // file instead of creating a duplicate alongside the old one.
                if ($existing->getName() !== $name) {
                    $renamed[] = sprintf('"%s" to "%s"', $existing->getName(), $name);
                    $existing->setName($name);
                }
                $matchedNames[$name] = true;
                $existing->setCounty($county);
                $this->applyEntry($existing, $entry, $county, $io);
                $updated++;
                continue;
            }

            $court = new Court();
            $court->setName($name);
            $court->setCounty($county);
            $court->setType(CourtType::from($entry['type']));
            $court->setActive(true);
            $this->applyEntry($court, $entry, $county, $io);

            $this->em->persist($court);
            $matchedNames[$name] = true;
            $created++;
        }

        $this->em->flush();

        foreach ($renamed as $message) {
            $io->note('Renamed ' . $message . '.');
        }

        // Courts the data file no longer mentions: either a rename this import
        // could not bridge, or a leftover duplicate. Both leave dossiers pointing
        // at a court that will stop being maintained.
        $orphans = [];
        foreach ($this->courtRepository->findAll() as $court) {
            $name = (string) $court->getName();
            if (!isset($matchedNames[$name])) {
                $orphans[] = $name;
            }
        }
        foreach ($orphans as $name) {
            $io->warning(sprintf('Court "%s" exists in the database but not in courts.json.', $name));
        }

        $io->success(sprintf(
            'Import finished: %d created, %d updated, %d skipped, %d not in the data file.',
            $created,
            $updated,
            $skipped,
            count($orphans),
        ));

        // Fail loudly: a silent success here is what let courts drift out of the
        // nomenclature unnoticed. Extra courts in the database are only reported,
        // since adding one by hand is legitimate; --strict gates them too.
        if ($ambiguous !== [] || $missingCounties !== [] || ($strict && $orphans !== [])) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $entry */
    private function applyEntry(Court $court, array $entry, County $county, SymfonyStyle $io): void
    {
        if (isset($entry['coveredLocalities']) && is_array($entry['coveredLocalities'])) {
            $court->clearCoveredCities();
            foreach ($entry['coveredLocalities'] as $localityName) {
                $cityNormalized = LocalityNormalizer::normalize((string) $localityName);
                $city = $cityNormalized !== null
                    ? $this->cityRepository->findOneByCountyAndNormalizedName($county, $cityNormalized)
                    : null;
                if ($city === null) {
                    $io->warning(sprintf(
                        'City "%s" (county %s) not found, skipping coverage for court "%s".',
                        (string) $localityName,
                        $county->getName(),
                        $court->getName(),
                    ));
                    continue;
                }
                $court->addCoveredCity($city);
            }
        }
        if (isset($entry['address']) && is_string($entry['address'])) {
            $court->setAddress($entry['address']);
        }
        if (isset($entry['email']) && is_string($entry['email'])) {
            $court->setEmail($entry['email']);
        }
        if (isset($entry['phone']) && is_string($entry['phone'])) {
            $court->setPhone($entry['phone']);
        }
    }
}
