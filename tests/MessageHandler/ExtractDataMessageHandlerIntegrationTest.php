<?php

namespace App\Tests\MessageHandler;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Message\ExtractDataMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Nivel 2 — handler wired via the real container, dispatched onto an
 * `InMemoryTransport` (configured in `config/packages/test/messenger.yaml`),
 * and consumed by exercising the real handler chain. Verifies the
 * production wiring without paying for a real Doctrine queue or running
 * a separate worker process.
 *
 * The unit tests in {@see ExtractDataMessageHandlerTest} cover handler
 * logic with mocked collaborators; this test fills the gap they leave —
 * proving that the dispatcher → routing → handler resolution chain
 * actually delivers an `ExtractDataMessage` to the right invocation.
 */
class ExtractDataMessageHandlerIntegrationTest extends KernelTestCase
{
    private MessageBusInterface $bus;
    private TransportInterface $asyncTransport;
    private EntityManagerInterface $em;
    private string $emailMarker;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->bus = static::getContainer()->get(MessageBusInterface::class);
        // Symfony exposes each declared transport as a service id `messenger.transport.{name}`
        $this->asyncTransport = static::getContainer()->get('messenger.transport.async');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->emailMarker = 'msg-handler-int-' . uniqid() . '@test.com';
    }

    protected function tearDown(): void
    {
        // FK-safe cleanup: Document → LegalCase → User scoped by our marker email.
        $this->em->createQuery(
            'DELETE FROM App\Entity\Document d WHERE d.uploadedBy IN '
                . '(SELECT u FROM App\Entity\User u WHERE u.email = :email)',
        )->setParameter('email', $this->emailMarker)->execute();

        $this->em->createQuery(
            'DELETE FROM App\Entity\LegalCase c WHERE c.user IN '
                . '(SELECT u FROM App\Entity\User u WHERE u.email = :email)',
        )->setParameter('email', $this->emailMarker)->execute();

        $this->em->createQuery('DELETE FROM App\Entity\User u WHERE u.email = :email')
            ->setParameter('email', $this->emailMarker)
            ->execute();
    }

    public function testDispatchedMessageIsRoutedToTheAsyncTransport(): void
    {
        $document = $this->createDocumentInDb(extractionStatus: ExtractionStatus::PENDING);

        $this->bus->dispatch(new ExtractDataMessage($document->getId()));

        // The in-memory transport queues messages instead of consuming them
        // synchronously — confirms `App\Message\ExtractDataMessage: async` in
        // messenger.yaml actually points at this transport (and not at `sync`).
        $this->assertInstanceOf(InMemoryTransport::class, $this->asyncTransport);
        $sent = $this->asyncTransport->getSent();
        $this->assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(ExtractDataMessage::class, $message);
        $this->assertSame($document->getId(), $message->documentId);
    }

    public function testHandlerExecutionTransitionsDocumentToTerminalStatus(): void
    {
        // The cascade requires PdfParser/OcrText/AiVision/Stub to all run; only
        // Stub will support a Document with a fake stored path that doesn't
        // exist on disk. Stub returns 0 confidence → status FAILED. That's the
        // realistic terminal state for a Document the worker can't actually
        // read — and it's exactly what we want to assert: the orchestrator and
        // handler wiring lead to a terminal status, not a stuck PROCESSING.
        $document = $this->createDocumentInDb(extractionStatus: ExtractionStatus::PENDING);
        $documentId = $document->getId();

        $this->bus->dispatch(new ExtractDataMessage($documentId));

        // Manually invoke the handler on the queued envelope. We could spin a
        // real `messenger:consume --limit=1` worker here, but that adds process
        // boundary complexity for no extra coverage — the bus + transport +
        // routing are already exercised by the dispatch above.
        $envelope = $this->asyncTransport->get();
        $handler = static::getContainer()->get('App\\MessageHandler\\ExtractDataMessageHandler');
        foreach ($envelope as $env) {
            $handler($env->getMessage());
        }

        // Force a fresh fetch — handler ran on the same EM, but we want to
        // confirm the persisted state reflects the terminal transition.
        $this->em->clear();
        $refetched = $this->em->find(Document::class, $documentId);
        $this->assertNotNull($refetched);
        $this->assertContains(
            $refetched->getExtractionStatus(),
            [ExtractionStatus::COMPLETED, ExtractionStatus::FAILED],
            'Handler must drive the document to a terminal status; never leave it stuck in PROCESSING',
        );
    }

    public function testHandlerExitsCleanlyWhenDocumentDeletedBetweenDispatchAndConsume(): void
    {
        $document = $this->createDocumentInDb(extractionStatus: ExtractionStatus::PENDING);
        $documentId = $document->getId();

        $this->bus->dispatch(new ExtractDataMessage($documentId));

        // Simulate the orphan scenario — Document deleted between dispatch
        // and consume (race against the user clicking "remove document").
        $this->em->remove($document);
        $this->em->flush();
        $this->em->clear();

        $envelope = $this->asyncTransport->get();
        $handler = static::getContainer()->get('App\\MessageHandler\\ExtractDataMessageHandler');

        // Must NOT throw — handler ACKs orphan messages cleanly.
        foreach ($envelope as $env) {
            $handler($env->getMessage());
        }

        $this->assertNull($this->em->find(Document::class, $documentId));
    }

    private function createDocumentInDb(ExtractionStatus $extractionStatus): Document
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
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename('msg-int.pdf');
        $document->setStoredFilename('cases/msg-int-' . uniqid() . '.pdf');
        $document->setFileSize(1024);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($user);
        $document->setExtractionStatus($extractionStatus);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }
}
