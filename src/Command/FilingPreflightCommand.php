<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Filing\CourtFilingNotAllowedException;
use App\Service\Filing\RecipientOverrideGuard;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\InvalidArgumentException as MailerInvalidArgumentException;
use Symfony\Component\Mailer\Transport\Dsn;

/**
 * Checks the court filing configuration without connecting anywhere: meant for
 * deploys and health checks, so a misconfiguration is caught before a filing
 * goes to the wrong place.
 */
#[AsCommand(name: 'app:filing:preflight', description: 'Check the court filing email configuration (no network access)')]
final class FilingPreflightCommand extends Command
{
    public function __construct(
        private readonly RecipientOverrideGuard $recipientGuard,
        private readonly string $courtFilingFrom,
        private readonly string $courtFilingBounceDomain,
        private readonly string $courtFilingMailerDsn,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Court filing preflight');
        $errors = [];
        $warnings = [];

        try {
            $this->recipientGuard->assertConfiguration();
        } catch (CourtFilingNotAllowedException $e) {
            $errors[] = $e->getMessage();
        }

        if (!filter_var(trim($this->courtFilingFrom), FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'COURT_FILING_FROM is empty or not a valid email address.';
        }
        $domain = trim($this->courtFilingBounceDomain);
        if ($domain === '' || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($domain, '.')) {
            $errors[] = 'COURT_FILING_BOUNCE_DOMAIN is empty or not a valid domain.';
        }

        $dsn = null;
        try {
            $dsn = Dsn::fromString($this->courtFilingMailerDsn);
        } catch (MailerInvalidArgumentException) {
            $errors[] = 'COURT_FILING_MAILER_DSN cannot be parsed.';
        }

        $live = $this->recipientGuard->isLive();
        if ($live && $dsn !== null && ($dsn->getScheme() === 'null' || ($dsn->getHost() === 'mailer' && $dsn->getPort() === 1025))) {
            $warnings[] = 'COURT_FILING_LIVE=1 but the transport points at a local test server.';
        }

        $io->definitionList(
            ['Live mode' => $live ? 'on' : 'off'],
            ['Recipient override' => $this->recipientGuard->override() ?? '(none)'],
            ['Transport' => $dsn !== null ? $dsn->getScheme() . '://' . $dsn->getHost() . ($dsn->getPort() !== null ? ':' . $dsn->getPort() : '') : '(invalid)'],
        );

        foreach ($warnings as $warning) {
            $io->warning($warning);
        }
        if ($errors !== []) {
            $io->error($errors);

            return Command::FAILURE;
        }

        $io->success('Court filing configuration is consistent.');

        return Command::SUCCESS;
    }
}
