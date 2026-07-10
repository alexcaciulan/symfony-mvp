<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BnrExchangeRate;
use App\Repository\BnrExchangeRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Imports BNR reference exchange rates into the `bnr_exchange_rate` series.
 * Without --year, fetches the current file (last ~10 banking days); with
 * --year, fetches that year's archive. Registered as a daily Coolify job.
 */
#[AsCommand(
    name: 'app:import-exchange-rates',
    description: 'Import BNR reference exchange rates (EUR/USD) from bnr.ro XML.',
)]
class ImportExchangeRatesCommand extends Command
{
    private const NS = 'http://www.bnr.ro/xsd';
    private const CURRENT_URL = 'https://www.bnr.ro/nbrfxrates.xml';
    private const ARCHIVE_URL = 'https://www.bnr.ro/files/xml/years/nbrfxrates%d.xml';

    /** Currencies persisted; the UI exposes only EUR, USD is kept for future use. */
    private const CURRENCIES = ['EUR', 'USD'];

    public function __construct(
        private EntityManagerInterface $em,
        private BnrExchangeRateRepository $rateRepository,
        private HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'Import a full year archive (e.g. 2024) instead of the current file.')
            ->addOption('update', null, InputOption::VALUE_NONE, 'Overwrite existing rates instead of skipping them.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $year = $input->getOption('year');
        $updateMode = (bool) $input->getOption('update');

        $url = $year !== null
            ? sprintf(self::ARCHIVE_URL, (int) $year)
            : self::CURRENT_URL;

        try {
            $content = $this->httpClient->request('GET', $url, ['timeout' => 15])->getContent();
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to fetch BNR rates from %s: %s', $url, $e->getMessage()));
            return Command::FAILURE;
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);
        if ($xml === false) {
            $io->error('Invalid XML received from ' . $url);
            return Command::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        $body = $xml->children(self::NS)->Body ?? null;
        if ($body === null) {
            $io->error('Unexpected BNR XML structure (missing Body).');
            return Command::FAILURE;
        }

        foreach ($body->children(self::NS)->Cube as $cube) {
            $dateRaw = (string) $cube->attributes()->date;
            $rateDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw);
            if ($rateDate === false) {
                continue;
            }

            foreach ($cube->children(self::NS)->Rate as $rateNode) {
                $currency = (string) $rateNode->attributes()->currency;
                if (!in_array($currency, self::CURRENCIES, true)) {
                    continue;
                }

                $multiplierAttr = $rateNode->attributes()->multiplier;
                $multiplier = $multiplierAttr !== null ? (int) (string) $multiplierAttr : 1;
                if ($multiplier < 1) {
                    $multiplier = 1;
                }

                $normalized = (float) (string) $rateNode / $multiplier;
                $rateValue = sprintf('%.4f', $normalized);

                $existing = $this->rateRepository->findOneBy(['currency' => $currency, 'rateDate' => $rateDate]);
                if ($existing !== null) {
                    if (!$updateMode) {
                        $skipped++;
                        continue;
                    }
                    $existing->setRate($rateValue);
                    $updated++;
                    continue;
                }

                $rate = new BnrExchangeRate();
                $rate->setCurrency($currency);
                $rate->setRateDate($rateDate);
                $rate->setRate($rateValue);
                $this->em->persist($rate);
                $created++;
            }
        }

        $this->em->flush();

        $io->success(sprintf(
            'Exchange rate import finished (%s): %d created, %d updated, %d skipped.',
            $url,
            $created,
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
