<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Test source: the .eml files of a directory, plus messages queued by a test from
 * a real submission (its VERP token or Message-ID). Acknowledgements are kept in
 * memory so idempotency can be asserted without moving files.
 */
final class FixtureInboundSource implements InboundMailSourceInterface, ResetInterface
{
    public const NAME = 'fixture';

    /** @var array<string, string> id => raw */
    private array $queued = [];

    /** @var array<string, true> */
    private array $acknowledged = [];

    private bool $readDirectory = false;

    public function __construct(
        private readonly string $directory,
    ) {}

    /** Also serves the directory's .eml files (off by default, so a test sees only what it queued). */
    public function includeDirectory(bool $include = true): void
    {
        $this->readDirectory = $include;
    }

    public function queue(string $raw, string $id): void
    {
        $this->queued[$id] = $raw;
    }

    public function fetch(int $limit): iterable
    {
        $out = [];
        $candidates = $this->queued;
        if ($this->readDirectory && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*.eml') ?: [] as $file) {
                $candidates[basename($file)] ??= (string) file_get_contents($file);
            }
        }
        foreach ($candidates as $id => $raw) {
            if (isset($this->acknowledged[$id])) {
                continue;
            }
            $out[] = new RawInboundMail(self::NAME, $id, $raw, new \DateTimeImmutable());
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function acknowledge(RawInboundMail $mail): void
    {
        $this->acknowledged[$mail->sourceId] = true;
    }

    public function isAcknowledged(string $id): bool
    {
        return isset($this->acknowledged[$id]);
    }

    /** Forgets the acknowledgements only, as a source that lost its tags would. */
    public function forgetAcknowledgements(): void
    {
        $this->acknowledged = [];
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function reset(): void
    {
        $this->queued = [];
        $this->acknowledged = [];
        $this->readDirectory = false;
    }
}
