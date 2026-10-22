<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\InboundMail;
use App\Message\ProcessInboundMailMessage;
use App\Repository\InboundMailRepository;
use App\Service\Inbound\FilingInboundApplier;
use App\Service\Inbound\InboundAuthVerifier;
use App\Service\Inbound\InboundMailClassifier;
use App\Service\Inbound\InboundMailMatcher;
use App\Service\Inbound\InboundMailParser;
use App\Service\Inbound\InboundRawStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Classifies, authenticates, matches and applies one stored message, all in one
 * transaction. Idempotent: a processed message is left alone. Exceptions (a
 * deadlock against the filing worker, which locks in another order) roll the
 * whole step back and propagate, so the `async` retry runs it again from scratch.
 */
#[AsMessageHandler]
final class ProcessInboundMailMessageHandler
{
    public function __construct(
        private readonly InboundMailRepository $mails,
        private readonly InboundRawStorage $storage,
        private readonly InboundMailParser $parser,
        private readonly InboundMailClassifier $classifier,
        private readonly InboundAuthVerifier $authVerifier,
        private readonly InboundMailMatcher $matcher,
        private readonly FilingInboundApplier $applier,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $inboundPurgeDays,
    ) {}

    public function __invoke(ProcessInboundMailMessage $message): void
    {
        $mail = $this->mails->find($message->inboundMailId);
        if (!$mail instanceof InboundMail || $mail->getProcessedAt() !== null) {
            return;
        }
        $raw = $this->storage->read($mail);
        if ($raw === null) {
            // A copy that no longer matches its hash is never acted on.
            $this->logger->error('inbound.raw_unreadable', ['inboundMailId' => $mail->getId()]);
            $mail->markForReview('raw_unreadable');
            $mail->markProcessed($this->clock->now(), null);
            $this->em->flush();

            return;
        }

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $parsed = $this->parser->parse($raw);
            $classification = $this->classifier->classify($parsed);
            $auth = $this->authVerifier->verify($parsed);
            $match = $this->matcher->match($parsed, $auth);

            $mail->setClassification($classification);
            $mail->setAuthVerdict($auth);
            $mail->setDsn($parsed->dsn?->status, $parsed->dsn?->action, $parsed->dsn?->finalRecipient, $parsed->dsn?->diagnostic);
            $mail->match($match?->part, $match?->submission, $match?->method, $match?->confidence);

            $this->applier->apply($mail, $parsed);

            $now = $this->clock->now();
            $purgeAfter = $classification->isPurgeable() && $mail->getMatchedSubmission() === null
                ? $now->modify('+' . $this->inboundPurgeDays . ' days')
                : null;
            $mail->markProcessed($now, $purgeAfter);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }
}
