<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Service\Inbound\Imap\ImapMailbox;
use App\Service\Inbound\Imap\ImapMailboxFactory;
use App\Service\Inbound\Imap\ImapSettings;

/**
 * Production source: the platform mailbox over IMAP. Nothing connects until the
 * first fetch; a stored message is moved to the processed folder. The source id
 * names the host, the folder and its UIDVALIDITY next to the UID, so a mailbox
 * whose UIDs restart (another account, a recreated folder) never has its new
 * messages taken for ones already stored.
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
        $mailbox = str_contains($this->settings->username, '@') ? mb_strtolower($this->settings->username) : null;
        foreach ($this->mailbox()->fetch($limit) as $message) {
            $out[] = new RawInboundMail(
                self::NAME,
                $this->sourceId($message['uidvalidity'], $message['uid']),
                $message['raw'],
                $message['date'] ?? new \DateTimeImmutable(),
                $mailbox,
            );
        }

        return $out;
    }

    /** Moved only while the folder's UIDVALIDITY is the one the UID was read under. */
    public function acknowledge(RawInboundMail $mail): void
    {
        $prefix = $this->settings->host . '/' . $this->settings->inbox . ':';
        if (!str_starts_with($mail->sourceId, $prefix)
            || preg_match('/^([^:]*):(\d+)$/', substr($mail->sourceId, strlen($prefix)), $m) !== 1) {
            return;
        }
        if ($m[1] !== $this->mailbox()->uidValidity()) {
            return;
        }
        $this->mailbox()->moveToProcessed($m[2]);
    }

    private function sourceId(string $uidValidity, string $uid): string
    {
        return sprintf('%s/%s:%s:%s', $this->settings->host, $this->settings->inbox, $uidValidity, $uid);
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
