<?php

declare(strict_types=1);

namespace App\Service\Pdf;

final readonly class CompressionResult
{
    public function __construct(
        public bool $applied,
        public string $path,
        public int $bytesBefore,
        public int $bytesAfter,
    ) {}
}
