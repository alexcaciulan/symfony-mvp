<?php

declare(strict_types=1);

namespace App\Command;

use App\DTO\Billing\PartySnapshot;
use App\Entity\FiscalInvoice;
use App\Entity\User;
use App\Enum\EInvoiceStatus;
use App\Enum\FiscalInvoiceKind;
use App\Enum\FiscalInvoiceStatus;
use App\Repository\FiscalInvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mirrors a client's real invoices from Oblio into the local FiscalInvoice table.
 *
 * STRICTLY READ-ONLY towards Oblio: it only calls GET /docs/invoice/list and
 * never creates, edits, cancels or deletes anything in the Oblio account. All
 * writes go to our own database (the mirror), matched by (series, number) so it
 * is idempotent (re-running updates in place).
 *
 * The real PDF is not copied: we store Oblio's `link` and fetch it on demand at
 * download time.
 */
#[AsCommand(
    name: 'app:oblio:import-invoices',
    description: 'Read-only import of a client\'s Oblio invoices into the local mirror (no Oblio writes).',
)]
final class OblioImportInvoicesCommand extends Command
{
    private const BASE = 'https://www.oblio.eu/api';

    /**
     * @param array{name: string, cui?: string, onrcNumber?: string, address?: string, iban?: string} $supplier
     */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly EntityManagerInterface $em,
        private readonly FiscalInvoiceRepository $fiscalInvoices,
        #[Autowire('%env(OBLIO_EMAIL)%')] private readonly string $email,
        #[Autowire('%env(OBLIO_SECRET)%')] private readonly string $secret,
        #[Autowire('%env(OBLIO_CIF)%')] private readonly string $accountCif,
        #[Autowire('%app.invoice.supplier%')] private readonly array $supplier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', null, InputOption::VALUE_REQUIRED, 'App user email whose invoices to import (matched to Oblio client by CIF)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Oblio import (READ-ONLY on Oblio)');

        $email = (string) $input->getOption('user');
        $user = '' !== $email ? $this->em->getRepository(User::class)->findOneBy(['email' => $email]) : null;
        if (null === $user) {
            $io->error('Pass --user=<email> of an existing app user.');

            return Command::FAILURE;
        }
        $clientCif = $this->normalizeCif((string) $user->getCui());
        if ('' === $clientCif) {
            $io->error('User has no CUI; cannot match to an Oblio client.');

            return Command::FAILURE;
        }
        $io->writeln('Matching Oblio client CIF: <info>' . $clientCif . '</info>');

        $token = $this->token();
        if (null === $token) {
            $io->error('Auth failed (check OBLIO_* in .env.local).');

            return Command::FAILURE;
        }

        $supplierSnapshot = new PartySnapshot(
            name: $this->supplier['name'],
            cui: $this->supplier['cui'] ?? null,
            onrcNumber: $this->supplier['onrcNumber'] ?? null,
            address: $this->supplier['address'] ?? '',
            iban: $this->supplier['iban'] ?? null,
        );

        $imported = 0;
        $offset = 0;
        $limit = 100;
        do {
            $rows = $this->fetchPage($token, $offset, $limit);
            foreach ($rows as $row) {
                if ($this->normalizeCif((string) ($row['client']['cif'] ?? '')) !== $clientCif) {
                    continue;
                }
                $this->upsert($row, $user, $supplierSnapshot);
                ++$imported;
            }
            $offset += $limit;
        } while (count($rows) === $limit && $offset < 5000);

        $this->em->flush();
        $io->success(sprintf('Imported/updated %d invoice(s) for %s. Nothing was modified in Oblio.', $imported, $email));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsert(array $row, User $user, PartySnapshot $supplier): void
    {
        $series = (string) ($row['seriesName'] ?? '');
        $number = (string) ($row['number'] ?? '');
        if ('' === $series || '' === $number) {
            return;
        }

        $fiscal = $this->fiscalInvoices->findOneBy(['series' => $series, 'number' => $number]) ?? new FiscalInvoice();
        $fiscal->setUser($user)
            ->setSeries($series)
            ->setNumber($number)
            ->setProviderName('oblio')
            ->setProviderInvoiceId(isset($row['id']) ? (string) $row['id'] : $series . '/' . $number)
            ->setCurrency((string) ($row['currency'] ?? 'RON'))
            ->setIssuedAt($this->parseDate($row['issueDate'] ?? null))
            ->setDueAt($this->parseDate($row['dueDate'] ?? null))
            ->setKind(($row['storno'] ?? '0') === '1' ? FiscalInvoiceKind::STORNO : FiscalInvoiceKind::INVOICE)
            ->setStatus(($row['canceled'] ?? '0') === '1' ? FiscalInvoiceStatus::CANCELED : FiscalInvoiceStatus::ISSUED)
            ->setEInvoiceStatus(!empty($row['einvoice']) ? EInvoiceStatus::SENT : EInvoiceStatus::NOT_APPLICABLE)
            ->setPdfUrl(isset($row['link']) ? (string) $row['link'] : null)
            ->setSupplier($supplier)
            ->setBuyer($this->buyerSnapshot($row['client'] ?? []));

        $gross = number_format((float) ($row['total'] ?? 0), 2, '.', '');
        // The list gives only the total; the authoritative VAT split lives in the
        // PDF. Store the real gross; net/vat are left zero (shown as gross in UI).
        $fiscal->setGrossTotal($gross)->setNetTotal($gross)->setVatTotal('0.00');

        $this->em->persist($fiscal);
    }

    /**
     * @param array<string, mixed> $client
     */
    private function buyerSnapshot(array $client): PartySnapshot
    {
        $address = trim(implode(', ', array_filter([
            (string) ($client['address'] ?? ''),
            (string) ($client['city'] ?? ''),
            (string) ($client['state'] ?? ''),
        ])));

        return new PartySnapshot(
            name: (string) ($client['name'] ?? ''),
            cui: (string) ($client['cif'] ?? '') ?: null,
            onrcNumber: (string) ($client['rc'] ?? '') ?: null,
            address: $address,
            iban: (string) ($client['iban'] ?? '') ?: null,
            email: (string) ($client['email'] ?? '') ?: null,
            phone: (string) ($client['phone'] ?? '') ?: null,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPage(string $token, int $offset, int $limit): array
    {
        try {
            $data = $this->http->request('GET', self::BASE . '/docs/invoice/list', [
                'auth_bearer' => $token,
                'query' => ['cif' => $this->accountCif, 'offset' => $offset, 'limitPerPage' => $limit],
            ])->toArray(false);
        } catch (\Throwable) {
            return [];
        }

        $rows = $data['data'] ?? [];

        return is_array($rows) ? array_values($rows) : [];
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

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date ? $date : null;
    }

    private function normalizeCif(string $cif): string
    {
        $upper = strtoupper(trim($cif));

        return str_starts_with($upper, 'RO') ? substr($upper, 2) : $upper;
    }
}
