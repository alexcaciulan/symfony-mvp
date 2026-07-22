<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Enum\ExtractionStatus;
use App\Event\DataExtractedEvent;
use App\Message\ExtractDataMessage;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Gives PENDING_RETRY a way out.
 *
 * {@see \App\MessageHandler\ExtractDataMessageHandler} parks a document in
 * PENDING_RETRY and hands the message back to Messenger on a transient failure.
 * Once the retry budget is spent the message goes to the `failed` transport and
 * nothing else touches the document, so it would sit in a non-terminal status
 * forever: the wizard's "Continue" button stays disabled and the status frame
 * polls with no end. This listener closes the loop by writing the terminal
 * FAILED status when Messenger reports the last attempt.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final readonly class ExtractionRetryExhaustedSubscriber
{
    public function __construct(
        private DocumentRepository $documents,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ExtractDataMessage) {
            return;
        }

        $document = $this->documents->find($message->documentId);
        if ($document === null || $document->getExtractionStatus()->isTerminal()) {
            return;
        }

        $document->setExtractionStatus(ExtractionStatus::FAILED);
        $this->em->flush();

        $this->logger->error('extraction.retry_exhausted', [
            'documentId' => $message->documentId,
            'reason' => $document->getExtractionFailureReason()?->value,
        ]);

        // Same announcement the handler makes on its own terminal outcomes, so
        // the badge stops spinning without waiting for the next poll.
        $this->events->dispatch(new DataExtractedEvent($document));
    }
}
