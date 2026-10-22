<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Service\Inbound\Imap\ImapMailbox;
use App\Service\Inbound\Imap\ImapMailboxFactory;
use App\Service\Inbound\Imap\ImapSettings;

/**
 * Production source: the platform mailbox over IMAP. Nothing connects until the
 * first fetch; a stored message is moved to the processed folder.
 */
final class ImapInboundSource implements InboundMailSourceInterface
{
    public const NAME = 'imap';

    private ?ImapMailbox $mailbox = null;

    public function __construct(
        private readonly ImapSettings $settings,
        private readonly ImapMailboxFactory $factory,
    ) {}

    /** imaps://user:pass@host:993/INBOX?processed=Processed */
    public static function fromDsn(string $dsn, ImapMailboxFactory $factory): self
    {
        $parts = parse_url($dsn);
        if ($parts === false || ($parts['host'] ?? '') === '') {
            throw new \InvalidArgumentException('INBOUND_MAIL_DSN is not a valid IMAP DSN.');
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $tls = strtolower((string) $parts['scheme']) === 'imaps';
        $inbox = ltrim(rawurldecode((string) ($parts['path'] ?? '')), '/');

        return new self(new ImapSettings(
            (string) $parts['host'],
            (int) ($parts['port'] ?? ($tls ? 993 : 143)),
            $tls,
            rawurldecode((string) ($parts['user'] ?? '')),
            rawurldecode((string) ($parts['pass'] ?? '')),
            $inbox !== '' ? $inbox : 'INBOX',
            is_string($query['processed'] ?? null) && $query['processed'] !== '' ? $query['processed'] : 'Processed',
        ), $factory);
    }

    public function fetch(int $limit): iterable
    {
        $out = [];
        foreach ($this->mailbox()->fetch($limit) as $message) {
            $out[] = new RawInboundMail(self::NAME, $message['uid'], $message['raw'], $message['date'] ?? new \DateTimeImmutable());
        }

        return $out;
    }

    public function acknowledge(RawInboundMail $mail): void
    {
        $this->mailbox()->moveToProcessed($mail->sourceId);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function settings(): ImapSettings
    {
        return $this->settings;
    }

    private function mailbox(): ImapMailbox
    {
        return $this->mailbox ??= $this->factory->create($this->settings);
    }
}
