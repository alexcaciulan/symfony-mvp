<?php

namespace App\Command;

use App\Service\Court\LocalityNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dev-only generator. Rewrites the coveredLocalities of every judecatorie in
 * data/courts.json from the HG 1217/2023 annex (data/sources/hotarare-1217-2023.txt),
 * reconciling names via data/coverage-aliases.json. It does not touch the database:
 * app:import-courts --update loads the produced JSON into court_covered_city.
 *
 * Re-run only when the annex changes (a new HG amends the circumscriptions):
 * refresh the vendored .txt, adjust the alias map, run this, then app:import-courts.
 */
#[AsCommand(
    name: 'app:build-court-coverage',
    description: 'Rebuild data/courts.json coveredLocalities from the HG 1217/2023 annex (dev tooling)',
)]
class BuildCourtCoverageCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and report without writing courts.json.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $annexPath = $this->projectDir . '/data/sources/hotarare-1217-2023.txt';
        $aliasPath = $this->projectDir . '/data/coverage-aliases.json';
        $citiesPath = $this->projectDir . '/data/cities.json';
        $courtsPath = $this->projectDir . '/data/courts.json';

        foreach ([$annexPath, $aliasPath, $citiesPath, $courtsPath] as $p) {
            if (!file_exists($p)) {
                $io->error('File not found: ' . $p);
                return Command::FAILURE;
            }
        }

        $aliases = json_decode(file_get_contents($aliasPath), true);
        $cities = json_decode(file_get_contents($citiesPath), true);
        $courts = json_decode(file_get_contents($courtsPath), true);
        if (!is_array($aliases) || !is_array($cities) || !is_array($courts)) {
            $io->error('Invalid JSON in one of the data files.');
            return Command::FAILURE;
        }

        $courtRemap = $aliases['court_remap'] ?? [];
        $skipCourts = array_flip($aliases['skip_courts']['list'] ?? []);
        $courtAliases = $aliases['court_aliases']['map'] ?? [];
        $uatAliases = $aliases['uat_aliases']['map'] ?? [];
        $knownGaps = [];
        foreach ($aliases['known_gaps']['list'] ?? [] as $g) {
            $knownGaps[LocalityNormalizer::normalize($g['county']) . '|' . LocalityNormalizer::normalize($g['uat'])] = true;
        }

        // Index cities.json by (normCounty => [normName => true]) for validation.
        $cityIndex = [];
        foreach ($cities as $c) {
            $cityIndex[LocalityNormalizer::normalize($c['county'])][LocalityNormalizer::normalize($c['name'])] = true;
        }

        // Set of judecatorie names present in courts.json (resolution targets).
        $judNames = [];
        foreach ($courts as $c) {
            if (($c['type'] ?? null) === 'judecatorie') {
                $judNames[$c['name']] = true;
            }
        }

        $annex = $this->parseAnnex($annexPath);
        $io->writeln(sprintf('Parsed annex: %d judecatorie entries.', count($annex)));

        // Build coverage: courtsJsonName => [exact cities.json names]
        $coverage = [];
        $assigned = [];   // (normCounty|normUat) => [courtNames] for overlap detection
        $errors = [];

        foreach ($annex as $court => $info) {
            if (isset($skipCourts[$court])) {
                continue;
            }
            $target = $courtRemap[$court] ?? $courtAliases[$court] ?? $court;
            if (!isset($judNames[$target])) {
                $errors[] = sprintf('Court not in courts.json: annex "%s" -> "%s"', $court, $target);
                continue;
            }
            $countyRaw = $info['county'];
            $normCounty = LocalityNormalizer::normalize($countyRaw);
            foreach ($info['uats'] as $uatRaw) {
                $uat = preg_replace('/\s+-\s+(până la|în prezent).*$/u', '', $uatRaw);
                $uat = trim((string) $uat);
                $city = $uatAliases[$countyRaw . '|' . $uat] ?? null;
                if ($city === null) {
                    $normUat = LocalityNormalizer::normalize($uat);
                    if (isset($cityIndex[$normCounty][$normUat])) {
                        $city = $uat;
                    }
                }
                if ($city === null) {
                    $errors[] = sprintf('UAT not resolved: [%s] "%s" (court %s)', $countyRaw, $uatRaw, $court);
                    continue;
                }
                $normCity = LocalityNormalizer::normalize($city);
                if (!isset($cityIndex[$normCounty][$normCity])) {
                    $errors[] = sprintf('Alias target not in cities.json: [%s] "%s" -> "%s"', $countyRaw, $uatRaw, $city);
                    continue;
                }
                $coverage[$target][] = $city;
                $assigned[$normCounty . '|' . $normCity][] = $target;
            }
        }

        // Overlap detection (a UAT assigned to more than one court is legally impossible).
        $overlaps = [];
        foreach ($assigned as $key => $whoList) {
            $uniq = array_values(array_unique($whoList));
            if (count($uniq) > 1) {
                $overlaps[] = $key . ' -> ' . implode(', ', $uniq);
            }
        }

        if ($errors !== []) {
            $io->error(sprintf('%d unresolved entries:', count($errors)));
            $io->listing(array_slice($errors, 0, 40));
            return Command::FAILURE;
        }
        if ($overlaps !== []) {
            $io->error(sprintf('%d overlapping UATs (data error):', count($overlaps)));
            $io->listing(array_slice($overlaps, 0, 40));
            return Command::FAILURE;
        }

        // Apply to courts.json: rewrite coveredLocalities for every judecatorie
        // except Bucharest sectors (kept as-is: they use ["Sector N"]).
        $totalLinks = 0;
        foreach ($courts as &$c) {
            if (($c['type'] ?? null) !== 'judecatorie') {
                continue;
            }
            if ($this->isBucharestSector($c)) {
                continue;
            }
            $list = $coverage[$c['name']] ?? [];
            $list = array_values(array_unique($list));
            sort($list, SORT_FLAG_CASE | SORT_STRING);
            $c['coveredLocalities'] = $list;
            $totalLinks += count($list);
        }
        unset($c);

        // Gap report: cities.json UATs not covered (excluding Bucharest + known gaps).
        $coveredSet = array_fill_keys(array_keys($assigned), true);
        $gaps = [];
        foreach ($cities as $c) {
            $nc = LocalityNormalizer::normalize($c['county']);
            if ($nc === 'bucuresti') {
                continue;
            }
            $key = $nc . '|' . LocalityNormalizer::normalize($c['name']);
            if (!isset($coveredSet[$key]) && !isset($knownGaps[$key])) {
                $gaps[] = sprintf('[%s] %s', $c['county'], $c['name']);
            }
        }

        $io->section('Summary');
        $io->writeln(sprintf('Courts covered: %d | UAT links written: %d | overlaps: 0', count($coverage), $totalLinks));
        $io->writeln(sprintf('Known documented gaps (manual selection): %d', count($knownGaps)));
        if ($gaps !== []) {
            $io->warning(sprintf('%d UNEXPECTED gaps (cities.json UAT not covered and not documented):', count($gaps)));
            $io->listing(array_slice($gaps, 0, 40));
        } else {
            $io->writeln('Unexpected gaps: 0');
        }

        if ($dryRun) {
            $io->note('Dry run: courts.json not written.');
            return Command::SUCCESS;
        }

        $json = json_encode($courts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($courtsPath, $json);
        $io->success('data/courts.json updated. Run: php bin/console app:import-courts --update');

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array{county: string, uats: string[]}>
     */
    private function parseAnnex(string $path): array
    {
        $lines = explode("\n", (string) file_get_contents($path));
        $data = [];
        $county = null;
        $court = null;
        $inItems = false;

        foreach ($lines as $raw) {
            $ln = str_replace("\x0c", '', $raw);
            if (trim($ln) === '') {
                continue;
            }
            if (preg_match('/Tipărit de|Document Lege6|pag\.\s*\d+\s*din|Copyright/u', $ln)) {
                continue;
            }
            if (preg_match('/^\s*Jude[țt]ul\s+(.+?)\s*$/u', $ln, $m) && stripos($ln, 'sediul') === false) {
                $county = trim($m[1]);
                $inItems = false;
                continue;
            }
            if (preg_match('/^\s*\d+\.\s*(Judec[ăâa]toria[^,]*?)\s*(?:,\s*cu sediul.*)?$/u', $ln, $m)) {
                $court = trim($m[1]);
                $data[$court] ??= ['county' => $county, 'uats' => []];
                $inItems = false;
                continue;
            }
            if (preg_match('/^\s*(Municipii|Municipiu|Orașe|Oraș|Comune|Comuna|Sectoare|Sector)\s*$/u', $ln)) {
                $inItems = true;
                continue;
            }
            if ($inItems && $court !== null && preg_match('/^\s*\d+\.\s*(.+?)\s*$/u', $ln, $m)) {
                $name = trim($m[1]);
                if ($name !== '' && !ctype_digit($name)) {
                    $data[$court]['uats'][] = $name;
                }
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $court */
    private function isBucharestSector(array $court): bool
    {
        return LocalityNormalizer::normalize((string) ($court['county'] ?? '')) === 'bucuresti'
            && str_contains((string) ($court['name'] ?? ''), 'Sectorului');
    }
}
