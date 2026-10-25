<?php

declare(strict_types=1);

namespace App\Service\Inbound\Imap;

final readonly class ImapSettings
{
    public function __construct(
        public string $host,
        public int $port,
        public bool $tls,
        public string $username,
        #[\SensitiveParameter]
        public string $password,
        public string $inbox,
        public string $processedFolder,
    ) {}
}
