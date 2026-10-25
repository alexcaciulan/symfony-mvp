<?php

declare(strict_types=1);

namespace App\Service\Inbound;

use App\Entity\InboundMail;
use App\Message\ProcessInboundMailMessage;
use App\Repository\InboundMailRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Stores one fetched message and queues its processing in the same transaction
 * (the Doctrine transport shares the connection), so a stored message always has
 * its processing queued. Idempotent: the same source message, or the same bytes
 * under the same Message-ID from another source id, is stored once and processed
 * once; one found stored but never processed is queued again, up to a cap.
 */
final class InboundEmailIngestor
{
    /** A message that fails every time is not queued again forever; the heartbeat reports it. */
    public const MAX_REQUEUES = 3;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboundMailRepository $mails,
        private readonly InboundRawStorage $storage,
        private readonly InboundMailParser $parser,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return ?InboundMail the new row, or null when the message was already stored */
    public function ingest(RawInboundMail $raw): ?InboundMail
    {
        $existing = $this->mails->findOneBySource($raw->source, $raw->sourceId);
        if ($existing !== null) {
            $this->requeueIfUnprocessed($existing);

            return null;
        }
        $parsed = $this->parser->parse($raw->raw);
        $sha = hash('sha256', $raw->raw);
        $duplicate = $this->mails->findOneBySha256AndMessageId($sha, $parsed->messageId);
        if ($duplicate !== null) {
            $this->logger->info('inbound.duplicate', ['inboundMailId' => $duplicate->getId(), 'source' => $raw->source]);
            $this->requeueIfUnprocessed($duplicate);

            return null;
        }

        $now = $this->clock->now();
        $path = $this->storage->write($raw->raw, 'eml', $now);
        // Sources report UTC or the sender's zone; the database keeps the application's zone.
        $receivedAt = $raw->receivedAt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $mail = new InboundMail($raw->source, $raw->sourceId, $path, $sha, strlen($raw->raw), $receivedAt, $now);
        $mail->setHeaders(
            $parsed->messageId,
            $parsed->inReplyTo,
            $parsed->references !== [] ? implode(' ', $parsed->references) : null,
            $parsed->from,
            $parsed->to !== [] ? implode(', ', $parsed->to) : null,
            $parsed->cc,
            // Only a recipient our side recorded: the source's mailbox, or our MTA's header.
            $raw->deliveredTo ?? $parsed->trustedDeliveryRecipient,
            $parsed->subject,
        );
        $this->em->getConnection()->transactional(function () use ($mail): void {
            $this->em->persist($mail);
            $this->em->flush();
            $this->bus->dispatch(new ProcessInboundMailMessage((int) $mail->getId()));
        });

        return $mail;
    }

    /** @return bool whether the processing was queued again */
    public function requeueIfUnprocessed(InboundMail $mail): bool
    {
        if ($mail->getProcessedAt() !== null || $mail->getRequeueCount() >= self::MAX_REQUEUES) {
            return false;
        }
        $this->em->getConnection()->transactional(function () use ($mail): void {
            $mail->markRequeued($this->clock->now());
            $this->em->flush();
            $this->bus->dispatch(new ProcessInboundMailMessage((int) $mail->getId()));
        });
        $this->logger->warning('inbound.requeued', ['inboundMailId' => $mail->getId(), 'requeueCount' => $mail->getRequeueCount()]);

        return true;
    }
}
