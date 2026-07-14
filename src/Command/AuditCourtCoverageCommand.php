<?php

namespace App\Command;

use App\Service\Court\LocalityNormalizer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Verifies the territorial coverage loaded into court_covered_city:
 *  - overlaps: a UAT covered by more than one judecatorie (legally impossible, data error) -> FAILURE
 *  - gaps: a non-Bucharest UAT covered by no judecatorie, excluding data/coverage-aliases.json known_gaps -> FAILURE
 *  - judecatorii with zero coverage: reported for information (de-facto suspended courts are expected)
 * Safe to run in prod after app:import-courts as a post-import guard.
 */
#[AsCommand(
    name: 'app:audit-court-coverage',
    description: 'Audit court_covered_city for gaps and overlaps against the UAT nomenclature',
)]
class AuditCourtCoverageCommand extends Command
{
    public function __construct(
        private Connection $db,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $knownGaps = [];
        $aliasPath = $this->projectDir . '/data/coverage-aliases.json';
        if (file_exists($aliasPath)) {
            $aliases = json_decode(file_get_contents($aliasPath), true);
            foreach ($aliases['known_gaps']['list'] ?? [] as $g) {
                $knownGaps[LocalityNormalizer::normalize($g['county']) . '|' . LocalityNormalizer::normalize($g['uat'])] = true;
            }
        }

        // Overlaps: city linked to >1 judecatorie.
        $overlaps = $this->db->fetchAllAssociative(
            "SELECT ci.name AS city, co.name AS county, GROUP_CONCAT(crt.name SEPARATOR ' | ') AS courts
             FROM court_covered_city ccc
             JOIN court crt ON crt.id = ccc.court_id AND crt.type = 'judecatorie'
             JOIN city ci ON ci.id = ccc.city_id
             JOIN county co ON co.id = ci.county_id
             GROUP BY ci.id
             HAVING COUNT(DISTINCT crt.id) > 1"
        );

        // Gaps: non-Bucharest cities with no judecatorie coverage.
        $uncovered = $this->db->fetchAllAssociative(
            "SELECT ci.name AS city, co.name AS county
             FROM city ci
             JOIN county co ON co.id = ci.county_id
             WHERE co.normalized_name <> 'bucuresti'
               AND NOT EXISTS (
                 SELECT 1 FROM court_covered_city ccc
                 JOIN court crt ON crt.id = ccc.court_id AND crt.type = 'judecatorie'
                 WHERE ccc.city_id = ci.id
               )
             ORDER BY co.name, ci.name"
        );
        $unexpectedGaps = [];
        $documentedGaps = 0;
        foreach ($uncovered as $row) {
            $key = LocalityNormalizer::normalize($row['county']) . '|' . LocalityNormalizer::normalize($row['city']);
            if (isset($knownGaps[$key])) {
                $documentedGaps++;
            } else {
                $unexpectedGaps[] = sprintf('[%s] %s', $row['county'], $row['city']);
            }
        }

        // Judecatorii with zero coverage (informational).
        $emptyCourts = $this->db->fetchFirstColumn(
            "SELECT crt.name
             FROM court crt
             WHERE crt.type = 'judecatorie'
               AND NOT EXISTS (SELECT 1 FROM court_covered_city ccc WHERE ccc.court_id = crt.id)
             ORDER BY crt.name"
        );

        $io->section('Court coverage audit');
        $io->writeln(sprintf('Overlapping UATs: %d', count($overlaps)));
        $io->writeln(sprintf('Documented gaps (allow-listed): %d', $documentedGaps));
        $io->writeln(sprintf('Unexpected gaps: %d', count($unexpectedGaps)));
        $io->writeln(sprintf('Judecatorii with zero coverage: %d', count($emptyCourts)));

        if ($emptyCourts !== []) {
            $io->writeln('  (' . implode(', ', $emptyCourts) . ')');
        }

        $ok = true;
        if ($overlaps !== []) {
            $ok = false;
            $io->error('Overlapping UATs (a locality cannot belong to two judecatorii):');
            $io->listing(array_map(
                static fn (array $r): string => sprintf('[%s] %s -> %s', $r['county'], $r['city'], $r['courts']),
                array_slice($overlaps, 0, 40),
            ));
        }
        if ($unexpectedGaps !== []) {
            $ok = false;
            $io->error('Unexpected uncovered UATs (add coverage or document in coverage-aliases.json):');
            $io->listing(array_slice($unexpectedGaps, 0, 40));
        }

        if (!$ok) {
            return Command::FAILURE;
        }

        $io->success('Coverage audit passed: 0 overlaps, 0 unexpected gaps.');
        return Command::SUCCESS;
    }
}
