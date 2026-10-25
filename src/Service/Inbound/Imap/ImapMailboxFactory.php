<?php

declare(strict_types=1);

namespace App\Service\Inbound\Imap;

/** Builds the mailbox adapter; a seam so the source is tested without a server. */
class ImapMailboxFactory
{
    public function create(ImapSettings $settings): ImapMailbox
    {
        return new WebklexImapMailbox($settings);
    }
}
