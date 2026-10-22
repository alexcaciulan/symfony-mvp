<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\FilingEmailPart;
use App\Entity\FilingSubmission;
use App\Service\Filing\RecipientOverrideGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

/**
 * Dev only: writes to the local Mailpit the answers a court mailbox or a mail
 * server could send about a submission (receipt, read receipt, delivery reports,
 * a clerk's reply), so the inbound processing can be checked end to end. Every
 * message is marked as simulated, uses .test addresses only, and is refused unless
 * the simulator is enabled, the app is not in prod and filings go to the override.
 */
final class CourtReplySimulator
{
    public const KINDS = ['receipt', 'read', 'bounce-permanent', 'bounce-temporary', 'bounce-size', 'delayed', 'reply', 'cc-bounce'];

    private const FORGED_AUTHSERV_ID = 'mx.forged.example.test';
    private const DAEMON = 'mailer-daemon@mail.instanta-simulata.example.test';

    public function __construct(
        #[Autowire(service: 'mailer.transports')]
        private readonly TransportInterface $transport,
        private readonly RecipientOverrideGuard $recipientGuard,
        private readonly bool $inboundSimulatorEnabled,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        private readonly string $inboundAuthservId,
        private readonly string $courtFilingFrom,
    ) {}

    /** @return ?string why it refuses, or null when it may run */
    public function refusal(): ?string
    {
        return match (true) {
            !$this->inboundSimulatorEnabled => 'INBOUND_SIMULATOR_ENABLED is off.',
            $this->environment === 'prod' => 'The simulator never runs in prod.',
            $this->recipientGuard->isLive() || $this->recipientGuard->override() === null => 'Filings are not redirected to the recipient override.',
            default => null,
        };
    }

    public function simulate(FilingSubmission $submission, string $kind, int $sequence = 1, bool $forgedAuth = false): string
    {
        $refusal = $this->refusal();
        if ($refusal !== null) {
            throw new \LogicException($refusal);
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown kind.');
        }
        $part = $submission->getPart($sequence) ?? throw new \InvalidArgumentException('The submission has no such email.');
        $court = $submission->getToAddress();
        $now = new \DateTimeImmutable();

        [$from, $to, $raw] = match ($kind) {
            'receipt' => [$court, $this->courtFilingFrom, $this->courtAnswer($part, $court, $now, $forgedAuth, 'Confirmare de primire: ' . $part->getSubject(),
                "Auto-Submitted: auto-replied\r\n", "Confirmăm primirea mesajului dumneavoastră la registratura instanței.\r\nAcest mesaj este generat automat.")],
            'reply' => [$court, $this->courtFilingFrom, $this->courtAnswer($part, $court, $now, $forgedAuth, 'Re: ' . $part->getSubject(), '',
                "Bună ziua,\r\n\r\nVă rugăm să transmiteți și dovada achitării taxei de timbru.\r\n\r\nGrefa")],
            'read' => [$court, $this->courtFilingFrom, $this->mdn($part, $court, $now, $forgedAuth)],
            'bounce-permanent' => [self::DAEMON, $this->verp($submission, $part), $this->dsn($part, $court, $now, 'failed', '5.1.1', '550 5.1.1 Recipient address rejected: User unknown')],
            'bounce-size' => [self::DAEMON, $this->verp($submission, $part), $this->dsn($part, $court, $now, 'failed', '5.3.4', '552 5.3.4 Message size exceeds fixed maximum message size')],
            'bounce-temporary' => [self::DAEMON, $this->verp($submission, $part), $this->dsn($part, $court, $now, 'failed', '4.4.7', '421 4.4.7 Message expired in queue')],
            'delayed' => [self::DAEMON, $this->verp($submission, $part), $this->dsn($part, $court, $now, 'delayed', '4.4.1', '451 4.4.1 Connection timed out')],
            'cc-bounce' => [self::DAEMON, $this->verp($submission, $part), $this->dsn($part, $submission->getCcAddress(), $now, 'failed', '5.2.2', '552 5.2.2 Mailbox full')],
        };

        $this->transport->send(new RawMessage($raw), new Envelope(new Address($from), [new Address($to)]));

        return $to;
    }

    private function verp(FilingSubmission $submission, FilingEmailPart $part): string
    {
        $domain = substr($submission->getEnvelopeSender(), (int) strrpos($submission->getEnvelopeSender(), '@') + 1);

        return sprintf('bounces+%s@%s', $part->getVerpToken(), $domain);
    }

    private function authHeader(bool $forged): string
    {
        $id = $forged ? self::FORGED_AUTHSERV_ID : $this->inboundAuthservId;

        return sprintf("Authentication-Results: %s; dkim=pass header.d=instanta-test.just.ro; spf=pass smtp.mailfrom=instanta-test.just.ro; dmarc=pass header.from=instanta-test.just.ro\r\n", $id);
    }

    private function headers(string $from, string $to, \DateTimeImmutable $now, string $subject): string
    {
        return sprintf(
            "From: %s\r\nTo: %s\r\nDate: %s\r\nSubject: %s\r\nMessage-ID: <sim-%s@instanta-simulata.example.test>\r\nX-Simulated: 1\r\nMIME-Version: 1.0\r\n",
            $from,
            $to,
            $now->format(\DateTimeInterface::RFC2822),
            mb_encode_mimeheader('[SIMULAT] ' . $subject, 'UTF-8', 'Q', "\r\n"),
            bin2hex(random_bytes(8)),
        );
    }

    private function courtAnswer(FilingEmailPart $part, string $court, \DateTimeImmutable $now, bool $forged, string $subject, string $extraHeaders, string $body): string
    {
        return $this->authHeader($forged)
            . $this->headers($court, $this->courtFilingFrom, $now, $subject)
            . sprintf("In-Reply-To: %s\r\nReferences: %s\r\n", $part->getMessageId(), $part->getMessageId())
            . $extraHeaders
            . "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $body . "\r\n";
    }

    private function mdn(FilingEmailPart $part, string $court, \DateTimeImmutable $now, bool $forged): string
    {
        $boundary = 'sim-mdn-' . bin2hex(random_bytes(6));

        return $this->authHeader($forged)
            . $this->headers($court, $this->courtFilingFrom, $now, 'Citit: ' . $part->getSubject())
            . sprintf("Content-Type: multipart/report; report-type=disposition-notification; boundary=\"%s\"\r\n\r\n", $boundary)
            . "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nMesajul a fost afișat de destinatar.\r\n\r\n"
            . "--$boundary\r\nContent-Type: message/disposition-notification\r\n\r\n"
            . sprintf("Reporting-UA: instanta-simulata.example.test\r\nFinal-Recipient: rfc822; %s\r\nOriginal-Message-ID: %s\r\nDisposition: manual-action/MDN-sent-manually; displayed\r\n\r\n", $court, $part->getMessageId())
            . "--$boundary--\r\n";
    }

    private function dsn(FilingEmailPart $part, string $recipient, \DateTimeImmutable $now, string $action, string $status, string $diagnostic): string
    {
        $boundary = 'sim-dsn-' . bin2hex(random_bytes(6));
        $submission = $part->getSubmission();

        return "Return-Path: <>\r\n"
            . $this->headers(self::DAEMON, $this->verp($submission, $part), $now, $action === 'delayed' ? 'Delayed Mail (still being retried)' : 'Undelivered Mail Returned to Sender')
            . "Auto-Submitted: auto-replied\r\n"
            . sprintf("Content-Type: multipart/report; report-type=delivery-status; boundary=\"%s\"\r\n\r\n", $boundary)
            . "--$boundary\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\nThis is a simulated delivery report.\r\n\r\n"
            . "--$boundary\r\nContent-Type: message/delivery-status\r\n\r\n"
            . "Reporting-MTA: dns; mail.instanta-simulata.example.test\r\n\r\n"
            . sprintf("Final-Recipient: rfc822; %s\r\nAction: %s\r\nStatus: %s\r\nDiagnostic-Code: smtp; %s\r\n\r\n", $recipient, $action, $status, $diagnostic)
            . "--$boundary\r\nContent-Type: text/rfc822-headers\r\n\r\n"
            . sprintf("Message-ID: %s\r\nX-LexRecovery-Ref: %s\r\nFrom: %s\r\nSubject: %s\r\n\r\n", $part->getMessageId(), $submission->getToken(), $this->courtFilingFrom, mb_encode_mimeheader($part->getSubject(), 'UTF-8', 'Q', "\r\n"))
            . "--$boundary--\r\n";
    }
}
