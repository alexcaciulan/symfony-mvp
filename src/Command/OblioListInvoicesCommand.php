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
 * READ-ONLY: lists invoices from Oblio (GET /docs/invoice/list) and prints the
 * raw fields returned per row, to see whether the list includes a real
 * number + PDF link (creates/changes nothing).
 */
#[AsCommand(
    name: 'app:oblio:list-invoices',
    description: 'Read-only: list Oblio invoices and show returned fields (number, link, ...).',
)]
final class OblioListInvoicesCommand extends Command
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
        $io->title('Oblio invoice list (read-only)');

        $token = $this->token();
        if (null === $token) {
            $io->error('Auth failed (check OBLIO_* in .env.local).');

            return Command::FAILURE;
        }

        try {
            $response = $this->http->request('GET', self::BASE . '/docs/invoice/list', [
                'auth_bearer' => $token,
                'query' => ['cif' => $this->cif, 'limitPerPage' => 3, 'offset' => 0],
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $io->error('List failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->writeln('<info>HTTP ' . $response->getStatusCode() . '</info>');
        $io->writeln(json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }

    private function token(): ?string
    {
        try {
            $data = $this->http->request('POST', self::BASE . '/authorize/token', [
                'body' => ['client_id' => $this->email, 'client_secret' => $this->secret],
            ])->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $token = $data['access_token'] ?? null;

        return is_string($token) && '' !== $token ? $token : null;
    }
}
