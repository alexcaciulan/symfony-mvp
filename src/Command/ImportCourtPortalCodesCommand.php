<?php

namespace App\Command;

use App\Repository\CourtRepository;
use App\Service\Court\CourtNameIndex;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what would change without writing to the database.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $jsonPath = $this->projectDir . '/data/court_portal_codes.json';
        $dryRun = (bool) $input->getOption('dry-run');

        if (!file_exists($jsonPath)) {
            $io->error('File not found: ' . $jsonPath);

            return Command::FAILURE;
        }

        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            $io->error('Invalid JSON in ' . $jsonPath);

            return Command::FAILURE;
        }

        $courts = $this->courtRepository->findAll();
        $index = new CourtNameIndex($courts);

        // portal_code is unique in the database, so a code still held by a stale
        // court would abort the whole import with a constraint violation.
        $holders = [];
        foreach ($courts as $court) {
            $code = $court->getPortalCode();
            if ($code !== null && $code !== '') {
                $holders[$code] = $court;
            }
        }

        $unchanged = 0;
        $unmatched = [];
        $ambiguous = [];
        $duplicateCodes = [];
        $conflicts = [];
        $seenCodes = [];
        $assignments = [];

        foreach ($data as $courtName => $portalCode) {
            $courtName = (string) $courtName;
            $portalCode = (string) $portalCode;

            // A code identifies exactly one institution on the portal; reusing one
            // would silently point two courts at the same docket.
            if (isset($seenCodes[$portalCode])) {
                $duplicateCodes[] = sprintf('"%s" and "%s" share code %s', $seenCodes[$portalCode], $courtName, $portalCode);
            }
            $seenCodes[$portalCode] = $courtName;

            if ($index->isAmbiguous($courtName)) {
                $ambiguous[] = $courtName;
                continue;
            }

            $court = $index->find($courtName);
            if ($court === null) {
                $unmatched[] = $courtName;
                continue;
            }

            if ($court->getPortalCode() === $portalCode) {
                $unchanged++;
                continue;
            }

            $assignments[] = [$court, $portalCode, $courtName];
        }

        // A court that is itself getting a new code frees the one it holds, so only
        // codes stuck on courts the data file never mentions are real conflicts.
        $reassigned = [];
        foreach ($assignments as [$court]) {
            $reassigned[spl_object_id($court)] = true;
        }
        foreach ($assignments as [$court, $portalCode, $courtName]) {
            $holder = $holders[$portalCode] ?? null;
            if ($holder !== null && $holder !== $court && !isset($reassigned[spl_object_id($holder)])) {
                $conflicts[] = sprintf(
                    'code %s belongs to "%s" but the data file assigns it to "%s"',
                    $portalCode,
                    $holder->getName(),
                    $courtName,
                );
            }
        }

        if ($conflicts !== []) {
            $this->em->clear();
            foreach ($conflicts as $message) {
                $io->error('Conflict: ' . $message . '.');
            }
            $io->error('Nothing was written. Resolve the duplicate courts first.');

            return Command::FAILURE;
        }

        $updated = count($assignments);

        if ($dryRun) {
            $this->em->clear();
        } else {
            // Two passes: codes that merely move between courts would otherwise
            // trip the unique index mid-flush, since MySQL checks per statement.
            foreach ($assignments as [$court]) {
                $court->setPortalCode(null);
            }
            $this->em->flush();

            foreach ($assignments as [$court, $portalCode]) {
                $court->setPortalCode($portalCode);
            }
            $this->em->flush();
        }

        foreach ($unmatched as $courtName) {
            $io->warning(sprintf('No court matches "%s". The nomenclature may have been renamed.', $courtName));
        }
        foreach ($ambiguous as $courtName) {
            $io->warning(sprintf('"%s" matches several courts. Resolve the duplicate before importing.', $courtName));
        }
        foreach ($duplicateCodes as $message) {
            $io->warning('Duplicate portal code: ' . $message . '.');
        }

        $io->success(sprintf(
            '%s: %d court(s) updated, %d already current, %d unmatched, %d ambiguous.',
            $dryRun ? 'Dry run finished' : 'Import finished',
            $updated,
            $unchanged,
            count($unmatched),
            count($ambiguous),
        ));

        // Unmatched entries mean courts silently lack a portal code, which disables
        // case auto-discovery for them. Fail loudly so deploys and cron catch it.
        if ($unmatched !== [] || $ambiguous !== [] || $duplicateCodes !== []) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
