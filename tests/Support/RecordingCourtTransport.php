<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Wraps the court filing transport in the test environment (itself `null://`):
 * records what would have been handed to the SMTP server, in order, and can be
 * told to fail the next sends with exceptions shaped like the SMTP transport's
 * (reply code, message and dialogue log). Nothing leaves the test process.
 */
final class RecordingCourtTransport implements TransportInterface
{
    /** @var list<array{bytes: string, envelope: Envelope}> */
    public array $sent = [];

    public ?\Throwable $failNext = null;

    /** @var list<\Throwable> */
    private array $failures = [];

    /** @var (callable(): void)|null */
    public $beforeSend = null;

    public function __construct(
        private readonly TransportInterface $inner,
    ) {}

    /** The next $times sends fail with the given exception. */
    public function failTimes(int $times, \Throwable $failure): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->failures[] = $failure;
        }
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if ($this->beforeSend !== null) {
            $hook = $this->beforeSend;
            // Runs once: a hook that re-enters the handler must not recurse.
            $this->beforeSend = null;
            $hook();
        }
        if ($this->failNext !== null) {
            $failure = $this->failNext;
            $this->failNext = null;

            throw $failure;
        }
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }
        $this->sent[] = ['bytes' => $message->toString(), 'envelope' => $envelope ?? throw new \LogicException('A court email always carries its envelope.')];

        return $this->inner->send($message, $envelope);
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->failNext = null;
        $this->failures = [];
        $this->beforeSend = null;
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    /** A server reply refusing the first RCPT (the court's address, or the override outside live mode). */
    public static function rcptRefused(int $code, string $text, string $recipient = 'instanta-simulata@example.test'): TransportException
    {
        return self::reply($code, $text, [
            ...self::opening(),
            '> RCPT TO:<' . $recipient . '>',
        ], [250, 251, 252]);
    }

    /** A reply refusing the lawyer's copy, after the court's RCPT was accepted. */
    public static function lawyerRcptRefused(int $code, string $text, string $court, string $lawyer): TransportException
    {
        return self::reply($code, $text, [
            ...self::opening(),
            '> RCPT TO:<' . $court . '>',
            '< 250 2.1.5 Ok',
            '> RCPT TO:<' . $lawyer . '>',
        ], [250, 251, 252]);
    }

    public static function mailFromRefused(int $code, string $text): TransportException
    {
        return self::reply($code, $text, [...self::opening(false), '> MAIL FROM:<bounces+x@lexrecovery.test>'], [250]);
    }

    /** A reply to the final dot, after the whole message was written. */
    public static function endOfDataRefused(int $code, string $text): TransportException
    {
        return self::reply($code, $text, [...self::afterData()], [250]);
    }

    public static function connectionRefused(): TransportException
    {
        return new TransportException('Connection could not be established with host "mailer:1025": stream_socket_client(): Unable to connect to mailer:1025 (Connection refused)');
    }

    public static function startTlsFailed(): TransportException
    {
        $e = new TransportException('Unable to connect with STARTTLS.');
        $e->appendDebug(self::log(['< 220 mailer ESMTP', '> EHLO [127.0.0.1]', '< 250-mailer', '< 250 STARTTLS', '> STARTTLS', '< 220 2.0.0 Ready to start TLS']));

        return $e;
    }

    /** The connection timed out after the final dot: the server may have queued the message. */
    public static function lostAfterData(): TransportException
    {
        $e = new TransportException('Connection to "mailer:1025" timed out.');
        $e->appendDebug(self::log(self::afterData()));

        return $e;
    }

    /** The connection broke while the message body was being written. */
    public static function lostDuringData(): TransportException
    {
        $e = new TransportException('Unable to write bytes on the wire.');
        $e->appendDebug(self::log([...self::opening(), '> RCPT TO:<instanta-simulata@example.test>', '< 250 Ok', '> DATA', '< 354 End data with <CR><LF>.<CR><LF>']));

        return $e;
    }

    /**
     * @param list<string> $dialogue
     * @param list<int>    $expected
     */
    private static function reply(int $code, string $text, array $dialogue, array $expected): TransportException
    {
        $line = trim($code . ' ' . $text);
        $e = new UnexpectedResponseException(sprintf('Expected response code "%s" but got code "%s", with message "%s".', implode('/', $expected), $code, $line), $code);
        $e->appendDebug(self::log([...$dialogue, '< ' . $line]));

        return $e;
    }

    /** @return list<string> */
    private static function opening(bool $withSender = true): array
    {
        $lines = ['< 220 mailer ESMTP', '> EHLO [127.0.0.1]', '< 250 mailer'];
        if ($withSender) {
            $lines[] = '> MAIL FROM:<bounces+x@lexrecovery.test>';
            $lines[] = '< 250 2.1.0 Ok';
        }

        return $lines;
    }

    /** @return list<string> */
    private static function afterData(): array
    {
        return [
            ...self::opening(),
            '> RCPT TO:<instanta-simulata@example.test>',
            '< 250 2.1.5 Ok',
            '> RCPT TO:<avocat@example.test>',
            '< 250 2.1.5 Ok',
            '> DATA',
            '< 354 End data with <CR><LF>.<CR><LF>',
            '> .',
        ];
    }

    /** @param list<string> $lines */
    private static function log(array $lines): string
    {
        $out = '';
        foreach ($lines as $line) {
            $out .= '[2026-10-22T10:00:00.000000+00:00] ' . $line . "\n";
        }

        return $out;
    }
}
