<?php

declare(strict_types=1);

namespace App\Service\Inbound\Imap;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/**
 * The production mailbox through webklex/php-imap (no ext-imap). It connects on
 * first use only, peeks without marking messages seen, and moves what was stored
 * to the processed folder.
 */
final class WebklexImapMailbox implements ImapMailbox
{
    private ?Client $client = null;

    public function __construct(
        private readonly ImapSettings $settings,
    ) {}

    public function fetch(int $limit): array
    {
        $out = [];
        $uidValidity = $this->uidValidity();
        $messages = $this->inbox()->query()->all()->leaveUnread()->setFetchOrder('asc')->limit($limit)->get();
        /** @var Message $message */
        foreach ($messages as $message) {
            $header = $message->getHeader();
            $raw = rtrim((string) $header?->raw) . "\r\n\r\n" . $message->getRawBody();
            $date = $message->getDate()?->first();
            $out[] = [
                'uid' => (string) $message->getUid(),
                'uidvalidity' => $uidValidity,
                'raw' => $raw,
                'date' => $date instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($date) : null,
            ];
        }

        return $out;
    }

    public function uidValidity(): string
    {
        return (string) ($this->inbox()->examine()['uidvalidity'] ?? '');
    }

    public function moveToProcessed(string $uid): void
    {
        $message = $this->inbox()->query()->getMessageByUid((int) $uid);
        $message->move($this->settings->processedFolder);
    }

    private function inbox(): Folder
    {
        return $this->connected()->getFolderByPath($this->settings->inbox) ?? throw new \RuntimeException('The inbound IMAP folder does not exist.');
    }

    private function connected(): Client
    {
        if ($this->client === null) {
            $this->client = (new ClientManager())->make([
                'host' => $this->settings->host,
                'port' => $this->settings->port,
                'encryption' => $this->settings->tls ? 'ssl' : false,
                'validate_cert' => true,
                'username' => $this->settings->username,
                'password' => $this->settings->password,
                'protocol' => 'imap',
            ]);
            $this->client->connect();
        }

        return $this->client;
    }
}
