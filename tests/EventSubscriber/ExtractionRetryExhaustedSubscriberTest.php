<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\ExtractionFailureReason;
use App\Enum\ExtractionStatus;
use App\Event\DataExtractedEvent;
use App\EventSubscriber\ExtractionRetryExhaustedSubscriber;
use App\Message\ExtractDataMessage;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * PENDING_RETRY is the only non-terminal status a finished handler can leave
 * behind. Messenger stops touching a message once its retries are spent, so
 * without this listener the document keeps that status forever: wizard step 0
 * never re-enables "Continue" and its status frame polls with no end.
 */
final class ExtractionRetryExhaustedSubscriberTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $emailMarker;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->emailMarker = 'retry-exhausted-' . uniqid() . '@test.com';
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE d FROM document d JOIN `user` u ON d.uploaded_by_id = u.id WHERE u.email = :email',
            ['email' => $this->emailMarker],
        );
        $conn->executeStatement(
            'DELETE lc FROM legal_case lc JOIN `user` u ON lc.user_id = u.id WHERE u.email = :email',
            ['email' => $this->emailMarker],
        );
        $conn->executeStatement('DELETE FROM `user` WHERE email = :email', ['email' => $this->emailMarker]);

        parent::tearDown();
    }

    public function testTheDocumentReachesFailedOnceMessengerGivesUp(): void
    {
        $document = $this->createDocument(ExtractionStatus::PENDING_RETRY);
        $documentId = $document->getId();

        ($this->makeSubscriber())($this->failureEvent($documentId, willRetry: false));

        $this->em->clear();
        $reloaded = $this->em->find(Document::class, $documentId);
        self::assertSame(ExtractionStatus::FAILED, $reloaded->getExtractionStatus());
        self::assertTrue($reloaded->getExtractionStatus()->isTerminal());
        // The cause survives the transition: it is what the badge explains and
        // what decides whether the retry button is offered.
        self::assertSame(ExtractionFailureReason::API_UNAVAILABLE, $reloaded->getExtractionFailureReason());
    }

    /**
     * While attempts remain, the queue is still working on the document and the
     * spinner is the honest badge.
     */
    public function testAnAttemptThatWillBeRetriedLeavesTheStatusAlone(): void
    {
        $document = $this->createDocument(ExtractionStatus::PENDING_RETRY);
        $documentId = $document->getId();

        ($this->makeSubscriber())($this->failureEvent($documentId, willRetry: true));

        $this->em->clear();
        $reloaded = $this->em->find(Document::class, $documentId);
        self::assertSame(ExtractionStatus::PENDING_RETRY, $reloaded->getExtractionStatus());
    }

    /**
     * A document the handler already settled must not be rewritten: a COMPLETED
     * extraction that failed later for an unrelated reason would otherwise lose
     * its result in the UI.
     */
    public function testATerminalDocumentIsNotOverwritten(): void
    {
        $document = $this->createDocument(ExtractionStatus::COMPLETED);
        $documentId = $document->getId();

        ($this->makeSubscriber())($this->failureEvent($documentId, willRetry: false));

        $this->em->clear();
        $reloaded = $this->em->find(Document::class, $documentId);
        self::assertSame(ExtractionStatus::COMPLETED, $reloaded->getExtractionStatus());
    }

    public function testTheUiIsNotifiedSoTheBadgeStopsSpinningWithoutAPoll(): void
    {
        $document = $this->createDocument(ExtractionStatus::PENDING_RETRY);
        $dispatched = [];
        $events = new class($dispatched) implements EventDispatcherInterface {
            /** @param array<int, object> $seen */
            public function __construct(public array &$seen) {}

            public function dispatch(object $event, ?string $eventName = null): object
            {
                $this->seen[] = $event;

                return $event;
            }
        };

        $subscriber = new ExtractionRetryExhaustedSubscriber(
            static::getContainer()->get(DocumentRepository::class),
            $this->em,
            $events,
        );
        $subscriber($this->failureEvent($document->getId(), willRetry: false));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(DataExtractedEvent::class, $dispatched[0]);
    }

    private function makeSubscriber(): ExtractionRetryExhaustedSubscriber
    {
        return new ExtractionRetryExhaustedSubscriber(
            static::getContainer()->get(DocumentRepository::class),
            $this->em,
            static::getContainer()->get('event_dispatcher'),
        );
    }

    private function failureEvent(int $documentId, bool $willRetry): WorkerMessageFailedEvent
    {
        $event = new WorkerMessageFailedEvent(
            new Envelope(new ExtractDataMessage($documentId)),
            'async',
            new \RuntimeException('transient extraction failure'),
        );
        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    private function createDocument(ExtractionStatus $status): Document
    {
        $user = new User();
        $user->setEmail($this->emailMarker);
        $user->setPassword('hashed');
        $user->setIsVerified(true);
        $this->em->persist($user);

        $case = new LegalCase();
        $case->setUser($user);
        $case->setStatus(CaseStatus::AMIABIL);
        $this->em->persist($case);

        $document = new Document();
        $document->setLegalCase($case);
        $document->setUploadedBy($user);
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename('retry.pdf');
        $document->setStoredFilename('cases/retry-' . uniqid() . '.pdf');
        $document->setFileSize(1024);
        $document->setMimeType('application/pdf');
        $document->setExtractionStatus($status);
        $document->setExtractionFailureReason(ExtractionFailureReason::API_UNAVAILABLE);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }
}
