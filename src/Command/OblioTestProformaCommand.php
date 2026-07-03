<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates a PROFORMA (not a fiscal invoice) on the real Oblio account to validate
 * that the provider accepts our payload structure end-to-end, then deletes it.
 *
 * A proforma consumes no fiscal number, is never sent to e-Factura/SPV, and is
 * freely deletable, so this is safe on a production account. It exercises the
 * same fields as invoice creation (client, products, series, VAT).
 */
#[AsCommand(
    name: 'app:oblio:test-proforma',
    description: 'Create + delete a test PROFORMA on Oblio (no fiscal invoice, no SPV).',
)]
final class OblioTestProformaCommand extends Command
{
    private const BASE = 'https://www.oblio.eu/api';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(OBLIO_EMAIL)%')] private readonly string $email,
        #[Autowire('%env(OBLIO_SECRET)%')] private readonly string $secret,
        #[Autowire('%env(OBLIO_CIF)%')] private readonly string $cif,
        #[Autowire('%app.invoice.vat_rate%')] private readonly int $vatRate = 21,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('client-cif', null, InputOption::VALUE_REQUIRED, 'Buyer CIF (defaults to own CIF = self test)')
            ->addOption('client-name', null, InputOption::VALUE_REQUIRED, 'Buyer name', 'TEST CLIENT PROFORMA');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Oblio test proforma (safe: not a fiscal invoice)');

        $clientCif = (string) ($input->getOption('client-cif') ?: $this->cif);
        $clientName = (string) $input->getOption('client-name');

        if ('' === $this->email || '' === $this->secret || '' === $this->cif) {
            $io->error('Missing OBLIO_EMAIL / OBLIO_SECRET / OBLIO_CIF in .env.local.');

            return Command::FAILURE;
        }

        $token = $this->token($io);
        if (null === $token) {
            return Command::FAILURE;
        }

        // A proforma needs a Proforma-type series in the account.
        $series = $this->findProformaSeries($token);
        if (null === $series) {
            $io->warning('No "Proforma" series found on the account. Create one in Oblio (Configurări > Serii documente > tip Proformă), then re-run.');

            return Command::FAILURE;
        }
        $io->writeln('Using proforma series: <info>' . $series . '</info>');

        // Build the same shape our real adapter sends for an invoice.
        $payload = [
            'cif' => $this->cif,
            'client' => ['cif' => $clientCif, 'name' => $clientName],
            'seriesName' => $series,
            'language' => 'RO',
            'currency' => 'RON',
            'issueDate' => (new \DateTimeImmutable())->format('Y-m-d'),
            'products' => [[
                'name' => 'Test abonament (proforma)',
                'price' => '99.00',
                'measuringUnit' => 'buc',
                'currency' => 'RON',
                'quantity' => '1',
                'vatName' => 'Normala',
                'vatPercentage' => (string) $this->vatRate,
                'vatIncluded' => 0,
            ]],
        ];

        $io->section('POST /docs/proforma');
        $created = $this->call('POST', '/docs/proforma', $token, ['json' => $payload]);
        if (null === $created) {
            return Command::FAILURE;
        }
        $io->writeln(json_encode($created, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        $number = $created['data']['number'] ?? null;
        $seriesName = $created['data']['seriesName'] ?? $series;
        $io->success(sprintf('Proforma accepted: %s %s (Oblio parsed our payload).', $seriesName, (string) $number));

        // Cleanup: delete the test proforma so nothing lingers.
        if (null !== $number) {
            $io->section('DELETE /docs/proforma (cleanup)');
            $deleted = $this->call('DELETE', '/docs/proforma', $token, ['query' => [
                'cif' => $this->cif,
                'seriesName' => $seriesName,
                'number' => $number,
            ]]);
            $io->writeln(null !== $deleted ? '<info>Deleted.</info>' : '<comment>Delete failed — remove it manually in Oblio.</comment>');
        }

        return Command::SUCCESS;
    }

    private function token(SymfonyStyle $io): ?string
    {
        try {
            $data = $this->http->request('POST', self::BASE . '/authorize/token', [
                'body' => ['client_id' => $this->email, 'client_secret' => $this->secret],
            ])->toArray(false);
        } catch (\Throwable $e) {
            $io->error('Auth failed: ' . $e->getMessage());

            return null;
        }

        $token = $data['access_token'] ?? null;

        return is_string($token) && '' !== $token ? $token : null;
    }

    private function findProformaSeries(string $token): ?string
    {
        $data = $this->call('GET', '/nomenclature/series', $token, ['query' => ['cif' => $this->cif]]);
        foreach ($data['data'] ?? [] as $series) {
            if (($series['type'] ?? '') === 'Proforma') {
                return $series['name'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>|null
     */
    private function call(string $method, string $path, string $token, array $options): ?array
    {
        try {
            $response = $this->http->request($method, self::BASE . $path, array_merge($options, ['auth_bearer' => $token]));
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            return null;
        }

        if (($data['status'] ?? 200) >= 400) {
            return null;
        }

        return $data;
    }
}
