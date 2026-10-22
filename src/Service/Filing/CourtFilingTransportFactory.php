<?php

declare(strict_types=1);

namespace App\Service\Filing;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The SMTP transport court filings leave through. Built without the mailer's
 * event dispatcher on purpose: the bytes written to disk before sending are the
 * bytes that go out, with no listener rewriting them.
 */
final class CourtFilingTransportFactory
{
    /** Well under the messenger redelivery window, so a slow server is not taken for a crash. */
    public const SOCKET_TIMEOUT_SECONDS = 60;

    public static function create(string $dsn): TransportInterface
    {
        $transport = Transport::fromDsn($dsn);
        if ($transport instanceof EsmtpTransport) {
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout(self::SOCKET_TIMEOUT_SECONDS);
            }
        }

        return $transport;
    }
}
