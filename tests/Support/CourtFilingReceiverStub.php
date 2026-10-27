<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The receiving side of the `court_filing` queue for a real Worker in a kernel
 * test. The in-memory transport hides an envelope until its DelayStamp elapses
 * on the wall clock, so a retry scheduled 5 minutes ahead would never come back
 * in a test; this stub hands out whatever it was given, delay included, while
 * the retries are still sent through the container's own sender.
 */
final class CourtFilingReceiverStub implements TransportInterface
{
    /** @var list<Envelope> */
    private array $queue = [];

    public function push(Envelope ...$envelopes): void
    {
        foreach ($envelopes as $envelope) {
            $this->queue[] = $envelope;
        }
    }

    public function isEmpty(): bool
    {
        return $this->queue === [];
    }

    public function get(): iterable
    {
        return $this->queue === [] ? [] : [$this->queue[0]];
    }

    public function ack(Envelope $envelope): void
    {
        array_shift($this->queue);
    }

    public function reject(Envelope $envelope): void
    {
        array_shift($this->queue);
    }

    public function send(Envelope $envelope): Envelope
    {
        throw new \LogicException('Retries are sent through the container sender, not the stub.');
    }
}
