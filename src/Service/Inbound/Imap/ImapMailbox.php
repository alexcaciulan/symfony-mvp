<?php

declare(strict_types=1);

namespace App\Service\Inbound\Imap;

/** The two operations the inbound poll needs from an IMAP mailbox. */
interface ImapMailbox
{
    /** @return list<array{uid: string, raw: string, date: ?\DateTimeImmutable}> */
    public function fetch(int $limit): array;

    public function moveToProcessed(string $uid): void;
}
