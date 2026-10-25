<?php

declare(strict_types=1);

namespace App\Service\Inbound\Imap;

/** The two operations the inbound poll needs from an IMAP mailbox. */
interface ImapMailbox
{
    /** @return list<array{uid: string, uidvalidity: string, raw: string, date: ?\DateTimeImmutable}> */
    public function fetch(int $limit): array;

    /** The folder's UIDVALIDITY now: UIDs are only unique while it stays the same. */
    public function uidValidity(): string;

    public function moveToProcessed(string $uid): void;
}
