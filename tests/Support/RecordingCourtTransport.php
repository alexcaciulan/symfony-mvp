<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Wraps the court filing transport in the test environment (itself `null://`):
 * records what would have been handed to the SMTP server, in order, and can be
 * told to fail the next send. Nothing leaves the test process.
 */
final class RecordingCourtTransport implements TransportInterface
{
    /** @var list<array{bytes: string, envelope: Envelope}> */
    public array $sent = [];

    public ?\Throwable $failNext = null;

    /** @var (callable(): void)|null */
    public $beforeSend = null;

    public function __construct(
        private readonly TransportInterface $inner,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->beforeSend !== null) {
            ($this->beforeSend)();
        }
        if ($this->failNext !== null) {
            $failure = $this->failNext;
            $this->failNext = null;

            throw $failure;
        }
        $this->sent[] = ['bytes' => $message->toString(), 'envelope' => $envelope ?? throw new \LogicException('A court email always carries its envelope.')];

        return $this->inner->send($message, $envelope);
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->failNext = null;
        $this->beforeSend = null;
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }
}
