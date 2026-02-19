<?php

namespace App\Tests\Controller;

use App\Entity\Court;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class DocumentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private User $otherUser;
    private Court $court;
    private string $uploadsDir;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->court = new Court();
        $this->court->setName('Judecătoria DocTest');
        $this->court->setCounty('Test');
        $this->court->setType(CourtType::JUDECATORIE);
        $this->court->setActive(true);
        $this->em->persist($this->court);

        $this->user = new User();
        $this->user->setEmail('doc-test-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Test');
        $this->user->setLastName('User');
        $this->em->persist($this->user);

        $this->otherUser = new User();
        $this->otherUser->setEmail('doc-other-' . uniqid() . '@test.com');
        $this->otherUser->setPassword($hasher->hashPassword($this->otherUser, 'password'));
        $this->otherUser->setIsVerified(true);
        $this->otherUser->setFirstName('Other');
        $this->otherUser->setLastName('User');
        $this->em->persist($this->otherUser);

        $this->em->flush();
        $this->client->loginUser($this->user);
    }

    private function createCaseWithPdf(): array
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus('pending_payment');
        $case->setCurrentStep(6);
        $case->setCounty('Test');
        $case->setCourt($this->court);
        $case->setClaimantType('pf');
        $case->setClaimantData(['name' => 'Test']);
        $case->setDefendants([['name' => 'Defendant']]);
        $case->setClaimAmount('1000.00');
        $case->setClaimDescription('Test');
        $case->setInterestType('none');
        $this->em->persist($case);
        $this->em->flush();

        // Create a test PDF file
        $dir = $this->uploadsDir . '/cases/' . $case->getId();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $storedFilename = 'cases/' . $case->getId() . '/cerere_' . $case->getId() . '.pdf';
        file_put_contents($this->uploadsDir . '/' . $storedFilename, '%PDF-1.4 test content');

        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType(DocumentType::CERERE_PDF);
        $document->setOriginalFilename('Cerere #' . $case->getId() . '.pdf');
        $document->setStoredFilename($storedFilename);
        $document->setFileSize(21);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($this->user);
        $this->em->persist($document);
        $this->em->flush();

        return [$case, $document];
    }

    public function testDownloadOwnDocument(): void
    {
        [$case, $document] = $this->createCaseWithPdf();

        $this->client->request('GET', "/case/{$case->getId()}/document/{$document->getId()}/download");

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/pdf');
    }

    public function testDownloadOtherUserDocumentDenied(): void
    {
        [$case, $document] = $this->createCaseWithPdf();

        // Login as other user
        $this->client->loginUser($this->otherUser);
        $this->client->request('GET', "/case/{$case->getId()}/document/{$document->getId()}/download");

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDownloadNonExistentDocumentReturns404(): void
    {
        [$case] = $this->createCaseWithPdf();

        $this->client->request('GET', "/case/{$case->getId()}/document/99999/download");

        $this->assertResponseStatusCodeSame(404);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();

        foreach ([$this->user, $this->otherUser] as $u) {
            $uid = $u->getId();
            $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$uid]);
            $conn->executeStatement('DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id WHERE lc.user_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM payment WHERE user_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$uid]);
            $conn->executeStatement('DELETE FROM user WHERE id = ?', [$uid]);
        }
        $conn->executeStatement('DELETE FROM court WHERE id = ?', [$this->court->getId()]);

        // Clean up test files
        $casesDir = $this->uploadsDir . '/cases';
        if (is_dir($casesDir)) {
            foreach (glob($casesDir . '/*/cerere_*.pdf') as $file) {
                @unlink($file);
            }
            foreach (glob($casesDir . '/*', GLOB_ONLYDIR) as $dir) {
                @rmdir($dir);
            }
        }

        parent::tearDown();
    }
}
