<?php

declare(strict_types=1);

namespace App\Tests\Controller\Case;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\ExtractionFailureReason;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionPipeline;
use App\Enum\ExtractionStatus;
use App\Message\ExtractDataMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The retry endpoint exists so a provider outage does not cost the lawyer the
 * upload. It must stay narrow: only transient causes, only documents from the
 * caller's own wizard session, only with a token.
 *
 * The account is pinned to LOCAL_ONLY so the upload's own extraction run stops
 * at the policy gate. This test is about re-queueing, not about extracting.
 */
final class WizardRetryExtractionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('wizard-retry-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Retry');
        $this->user->setLastName('Tester');
        $this->user->setExtractionPipeline(ExtractionPipeline::AI_ONLY);
        $this->user->setExtractionMode(ExtractionMode::LOCAL_ONLY);
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    public function testTransientFailureOffersRetryAndRequeuesTheDocument(): void
    {
        $document = $this->uploadDocument();
        $this->pinFailure($document, ExtractionFailureReason::API_UNAVAILABLE);

        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $this->retryToken($crawler, $document->getId());
        self::assertNotNull($token, 'A transient failure must offer the retry button');

        $this->drainTransport();
        $this->client->request('POST', '/case/new/documents/' . $document->getId() . '/retry', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/case/new/documents');
        self::assertCount(1, $this->transport()->getSent(), 'Retry must queue exactly one extraction message');
        self::assertInstanceOf(ExtractDataMessage::class, $this->transport()->getSent()[0]->getMessage());

        $this->em->clear();
        $reloaded = $this->em->find(Document::class, $document->getId());
        self::assertSame(ExtractionStatus::PENDING, $reloaded->getExtractionStatus());
        self::assertNull(
            $reloaded->getExtractionFailureReason(),
            'A stale cause must not outlive the attempt it belonged to',
        );
    }

    /**
     * Re-sending an oversized file fails identically and costs another call, so
     * the button is not offered and the endpoint refuses to queue.
     */
    public function testPermanentFailureNeitherOffersRetryNorRequeues(): void
    {
        $document = $this->uploadDocument();

        // Capture a genuine token while the failure still looks retriable, so
        // the POST below is stopped by the reason guard and not by CSRF.
        $this->pinFailure($document, ExtractionFailureReason::API_UNAVAILABLE);
        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $this->retryToken($crawler, $document->getId());
        self::assertNotNull($token);

        $this->pinFailure($document, ExtractionFailureReason::FILE_TOO_LARGE);
        $crawler = $this->client->request('GET', '/case/new/documents');
        self::assertNull(
            $this->retryToken($crawler, $document->getId()),
            'A permanent failure must not offer a retry button',
        );

        $this->drainTransport();
        $this->client->request('POST', '/case/new/documents/' . $document->getId() . '/retry', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/case/new/documents');
        self::assertCount(0, $this->transport()->getSent());

        $this->em->clear();
        $reloaded = $this->em->find(Document::class, $document->getId());
        self::assertSame(
            ExtractionFailureReason::FILE_TOO_LARGE,
            $reloaded->getExtractionFailureReason(),
        );
    }

    public function testRetryWithoutAValidTokenIsRejected(): void
    {
        $document = $this->uploadDocument();
        $this->pinFailure($document, ExtractionFailureReason::API_UNAVAILABLE);

        $this->drainTransport();
        $this->client->request('POST', '/case/new/documents/' . $document->getId() . '/retry', [
            '_token' => 'forged-token',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->transport()->getSent());
    }

    /**
     * Membership of the current wizard bag is the ownership check that matters
     * here, because step 0 documents have no LegalCase to authorise against.
     */
    public function testRetryOnADocumentOutsideTheWizardSessionIsNotFound(): void
    {
        $document = $this->uploadDocument();
        $this->pinFailure($document, ExtractionFailureReason::API_UNAVAILABLE);

        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $this->retryToken($crawler, $document->getId());

        // Starting a new draft empties the bag while the row and the session
        // token both survive, which is exactly the state the guard is for.
        $this->client->request('GET', '/case/new');

        $this->drainTransport();
        $this->client->request('POST', '/case/new/documents/' . $document->getId() . '/retry', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->transport()->getSent());
    }

    // ----- helpers -----

    private function uploadDocument(): Document
    {
        $crawler = $this->client->request('GET', '/case/new/documents');
        $token = $crawler->filter('form input[name="step0_documents[_token]"]')->first()->attr('value');
        $this->client->request(
            'POST',
            '/case/new/documents',
            parameters: ['step0_documents' => ['_token' => $token]],
            files: ['step0_documents' => ['documents' => [$this->makePdfUpload()]]],
        );
        self::assertResponseRedirects('/case/new/documents');

        $document = $this->em->getRepository(Document::class)->findOneBy(['uploadedBy' => $this->user]);
        self::assertNotNull($document);

        return $document;
    }

    /**
     * Puts the Document in the state the cascade would have left it in, without
     * running the cascade itself.
     */
    private function pinFailure(Document $document, ExtractionFailureReason $reason): void
    {
        $document->setExtractionStatus(ExtractionStatus::FAILED);
        $document->setExtractionFailureReason($reason);
        $document->setExtractionConfidence('0.00');
        $this->em->flush();
    }

    private function retryToken(Crawler $crawler, int $documentId): ?string
    {
        $input = $crawler->filter(
            sprintf('form[action="/case/new/documents/%d/retry"] input[name="_token"]', $documentId),
        );

        return $input->count() > 0 ? $input->first()->attr('value') : null;
    }


    private function transport(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    private function drainTransport(): void
    {
        $this->transport()->reset();
    }

    private function makePdfUpload(): UploadedFile
    {
        $fixture = dirname(__DIR__, 2) . '/fixtures/extraction/invoice-realistic.pdf';
        $tmp = tempnam(sys_get_temp_dir(), 'retry-pdf-');
        copy($fixture, $tmp);

        return new UploadedFile($tmp, 'invoice-realistic.pdf', 'application/pdf', UPLOAD_ERR_OK, true);
    }
}
