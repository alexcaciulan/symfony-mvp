<?php

declare(strict_types=1);

namespace App\Tests\Cascade;

use App\Entity\Document;
use App\Entity\User;
use App\Enum\ExtractionFailureReason;
use App\Enum\ExtractionMode;
use App\Enum\ExtractionPipeline;
use App\Enum\ExtractionStatus;
use App\MessageHandler\ExtractDataMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * An AI-only account whose privacy settings forbid AI has no strategy left to
 * run. The point of this test is that the lawyer is told so: a plain FAILED
 * would send them hunting for a broken document instead of to their settings.
 *
 * Runs the real cascade, but the account is on LOCAL_ONLY, so the run stops at
 * the policy gate and nothing is sent to any external service.
 */
final class WizardStep0AiOnlyPolicyTest extends WebTestCase
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
        $this->user->setEmail('ai-policy-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Policy');
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

    public function testDocumentFromAnAiOnlyAccountWithAiDisabledIsSkippedNotFailed(): void
    {
        $document = $this->uploadAndRunExtraction();

        self::assertSame(ExtractionStatus::SKIPPED_BY_POLICY, $document->getExtractionStatus());
        self::assertSame(
            ExtractionFailureReason::LOCAL_ONLY_MODE,
            $document->getExtractionFailureReason(),
        );
        self::assertSame('0.00', $document->getExtractionConfidence());
    }

    /**
     * The wizard must let the lawyer move on. A settings choice is not an
     * unfinished job, so the step must not wait for a status that will never
     * arrive.
     */
    public function testSkippedByPolicyIsTerminalSoTheWizardDoesNotWaitForever(): void
    {
        $document = $this->uploadAndRunExtraction();

        self::assertTrue($document->getExtractionStatus()->isTerminal());
    }

    public function testStepZeroExplainsTheSkipAndPointsAtTheSetting(): void
    {
        $this->uploadAndRunExtraction();

        $this->client->request('GET', '/case/new/documents');
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();

        self::assertStringContainsString('Extracție dezactivată', $html, 'The badge must name the outcome');
        self::assertStringContainsString(
            'dezactivată în setările tale',
            $html,
            'The lawyer must be told why nothing was extracted',
        );
        self::assertStringContainsString(
            '/profile/ai-agreement',
            $html,
            'The explanation must link to the setting that turns extraction back on',
        );
    }

    /**
     * Uploads one PDF through the wizard and runs the queued message through
     * the real handler, returning the reloaded Document.
     */
    private function uploadAndRunExtraction(): Document
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
        self::assertNotNull($document, 'Wizard upload must persist a Document');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);

        /** @var ExtractDataMessageHandler $handler */
        $handler = static::getContainer()->get(ExtractDataMessageHandler::class);
        $handler($sent[0]->getMessage());

        $this->em->clear();

        return $this->em->find(Document::class, $document->getId());
    }

    private function makePdfUpload(): UploadedFile
    {
        $fixture = dirname(__DIR__) . '/fixtures/extraction/invoice-realistic.pdf';
        $tmp = tempnam(sys_get_temp_dir(), 'policy-pdf-');
        copy($fixture, $tmp);

        return new UploadedFile($tmp, 'invoice-realistic.pdf', 'application/pdf', UPLOAD_ERR_OK, true);
    }
}
