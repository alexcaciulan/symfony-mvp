<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * READ-ONLY Oblio connectivity check. Authenticates (OAuth) and reads the
 * account nomenclatures (companies / series / VAT rates). It NEVER creates,
 * cancels or sends any document, so it is safe to run against a real account.
 *
 * Validates the real API communication (credentials, connectivity, OAuth,
 * response shapes) before any invoice-issuing path is exercised.
 */
#[AsCommand(
    name: 'app:oblio:ping',
    description: 'Read-only Oblio connectivity/credentials check (creates nothing).',
)]
final class OblioPingCommand extends Command
{
    private const BASE = 'https://www.oblio.eu/api';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(OBLIO_EMAIL)%')] private readonly string $email,
        #[Autowire('%env(OBLIO_SECRET)%')] private readonly string $secret,
        #[Autowire('%env(OBLIO_CIF)%')] private readonly string $cif,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Oblio ping (read-only)');

        if ('' === $this->email || '' === $this->secret || '' === $this->cif) {
            $io->error('Missing OBLIO_EMAIL / OBLIO_SECRET / OBLIO_CIF (set them in .env.local).');

            return Command::FAILURE;
        }

        // 1. OAuth token.
        try {
            $tokenResponse = $this->http->request('POST', self::BASE . '/authorize/token', [
                'body' => ['client_id' => $this->email, 'client_secret' => $this->secret],
            ]);
            $tokenData = $tokenResponse->toArray(false);
        } catch (\Throwable $e) {
            $io->error('Auth request failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $token = $tokenData['access_token'] ?? null;
        if (!is_string($token) || '' === $token) {
            $io->error('Auth failed (HTTP ' . $tokenResponse->getStatusCode() . '): no access_token. Body: ' . json_encode($tokenData));

            return Command::FAILURE;
        }
        $io->success('OAuth OK — token obtained (expires_in=' . ($tokenData['expires_in'] ?? '?') . 's).');

        // 2. Read-only nomenclatures (clients truncated: could be a long list).
        foreach (['companies', 'series', 'vat_rates', 'clients'] as $resource) {
            $io->section('GET /nomenclature/' . $resource);
            try {
                $response = $this->http->request('GET', self::BASE . '/nomenclature/' . $resource, [
                    'auth_bearer' => $token,
                    'query' => ['cif' => $this->cif],
                ]);
                $status = $response->getStatusCode();
                $data = $response->toArray(false);
            } catch (\Throwable $e) {
                $io->warning($resource . ' failed: ' . $e->getMessage());
                continue;
            }

            if ($status >= 400) {
                $io->warning('HTTP ' . $status . ': ' . json_encode($data));
                continue;
            }

            $rows = $data['data'] ?? $data;
            if (is_array($rows) && array_is_list($rows) && count($rows) > 5) {
                $io->writeln('<info>HTTP ' . $status . '</info> (' . count($rows) . ' rows, showing first 5)');
                $rows = array_slice($rows, 0, 5);
            } else {
                $io->writeln('<info>HTTP ' . $status . '</info>');
            }
            $io->writeln(json_encode($rows, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        }

        $io->newline();
        $io->success('Ping complete. No document was created, cancelled or sent to SPV.');

        return Command::SUCCESS;
    }
}
