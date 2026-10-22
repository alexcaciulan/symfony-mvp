<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Service\Inbound\Imap\ImapMailboxFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Picks the inbound source from INBOUND_MAIL_DSN, a deploy-time choice read once:
 * Mailpit in dev, a fixture directory in tests, an IMAP mailbox in production.
 * An empty DSN reads nothing; an unknown scheme is a configuration error.
 */
final class InboundMailSourceResolver
{
    private ?InboundMailSourceInterface $resolved = null;

    public function __construct(
        private readonly string $inboundMailDsn,
        private readonly string $courtFilingFrom,
        private readonly string $courtFilingBounceDomain,
        private readonly HttpClientInterface $httpClient,
        private readonly ImapMailboxFactory $imapMailboxFactory,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    public function resolve(): InboundMailSourceInterface
    {
        return $this->resolved ??= $this->build(trim($this->inboundMailDsn));
    }

    private function build(string $dsn): InboundMailSourceInterface
    {
        if ($dsn === '') {
            return new NullInboundSource();
        }
        $scheme = strtolower((string) strstr($dsn, '://', true));

        return match ($scheme) {
            'mailpit' => MailpitInboundSource::fromDsn($dsn, $this->httpClient, $this->courtFilingFrom, $this->courtFilingBounceDomain, $this->logger),
            'fixture' => new FixtureInboundSource($this->fixtureDirectory(substr($dsn, strlen('fixture://')))),
            'imaps', 'imap' => ImapInboundSource::fromDsn($dsn, $this->imapMailboxFactory),
            default => throw new \InvalidArgumentException(sprintf('Unsupported INBOUND_MAIL_DSN scheme "%s": use mailpit://, fixture://, imaps:// or leave it empty.', $scheme)),
        };
    }

    private function fixtureDirectory(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;
    }
}
