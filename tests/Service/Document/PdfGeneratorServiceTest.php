<?php

namespace App\Tests\Service\Document;

use App\Entity\Court;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtType;
use App\Enum\DocumentType;
use App\Service\Document\PdfGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PdfGeneratorServiceTest extends KernelTestCase
{
    private PdfGeneratorService $service;
    private EntityManagerInterface $em;
    private User $user;
    private Court $court;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(PdfGeneratorService::class);
        $this->em = $container->get(EntityManagerInterface::class);

        $this->court = new Court();
        $this->court->setName('Judecătoria PDF Test');
        $this->court->setCounty('Test');
        $this->court->setType(CourtType::JUDECATORIE);
        $this->court->setActive(true);
        $this->em->persist($this->court);

        $this->user = new User();
        $this->user->setEmail('pdf-test-' . uniqid() . '@test.com');
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Test');
        $this->user->setLastName('User');
        $this->em->persist($this->user);

        $this->em->flush();
    }

    public function testGenerateCasePdfCreatesFileAndDocument(): void
    {
        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setStatus('pending_payment');
        $case->setCurrentStep(6);
        $case->setCounty('Test');
        $case->setCourt($this->court);
        $case->setClaimantType('pf');
        $case->setClaimantData([
            'name' => 'Ion Popescu',
            'email' => 'ion@test.com',
            'cnp' => '1234567890123',
            'phone' => '0722000000',
            'street' => 'Str. Exemplu',
            'streetNumber' => '10',
            'city' => 'București',
            'county' => 'București',
        ]);
        $case->setDefendants([
            ['type' => 'pf', 'name' => 'Maria Ionescu', 'city' => 'Cluj', 'county' => 'Cluj'],
        ]);
        $case->setClaimAmount('5000.00');
        $case->setClaimDescription('Datorie neplătită conform contract.');
        $case->setInterestType('none');
        $case->setSubmittedAt(new \DateTimeImmutable());
        $this->em->persist($case);
        $this->em->flush();

        $document = $this->service->generateCasePdf($case);

        $this->assertSame(DocumentType::CERERE_PDF, $document->getDocumentType());
        $this->assertSame('application/pdf', $document->getMimeType());
        $this->assertGreaterThan(0, $document->getFileSize());
        $this->assertStringContainsString('cases/' . $case->getId(), $document->getStoredFilename());

        // Verify file on disk
        $uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $filePath = $uploadsDir . '/' . $document->getStoredFilename();
        $this->assertFileExists($filePath);
        $this->assertGreaterThan(0, filesize($filePath));

        // Verify PDF header
        $content = file_get_contents($filePath);
        $this->assertStringStartsWith('%PDF', $content);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $userId = $this->user->getId();
        $courtId = $this->court->getId();

        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$userId]);
        $conn->executeStatement('DELETE csh FROM case_status_history csh JOIN legal_case lc ON csh.legal_case_id = lc.id WHERE lc.user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM user WHERE id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM court WHERE id = ?', [$courtId]);

        // Clean up generated files
        $uploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads/cases';
        if (is_dir($uploadsDir)) {
            $files = glob($uploadsDir . '/*/cerere_*.pdf');
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }
}
