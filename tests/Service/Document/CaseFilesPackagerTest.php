<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Service\Document\CaseFilesPackager;
use App\Service\Document\MissingDocumentFileException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 5.2 — Tests for CaseFilesPackager. Verifies ZIP creation cu structura
 * numbered top-level (01_cerere_op, 02_opis, 03_somatie) + dovada_comunicare/
 * + anexe/. Documents must exist on disk pentru a fi adăugate la ZIP.
 */
final class CaseFilesPackagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CaseFilesPackager $packager;
    private User $user;
    private LegalCase $case;
    private string $uploadsDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->packager = $container->get(CaseFilesPackager::class);
        $this->uploadsDir = $container->getParameter('kernel.project_dir') . '/var/uploads';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail('packager-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Packager');
        $this->user->setLastName('Test');
        $this->em->persist($this->user);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setAmount('1000.00');
        $this->case->setCurrency('RON');
        $this->em->persist($this->case);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $caseId = $this->case->getId();

        // Cleanup ZIP package + test PDFs
        $packageDir = $this->uploadsDir . '/cases/' . $caseId . '/packages';
        if (is_dir($packageDir)) {
            foreach (glob($packageDir . '/*.zip') ?: [] as $zipFile) {
                @unlink($zipFile);
            }
            @rmdir($packageDir);
        }
        $caseDir = $this->uploadsDir . '/cases/' . $caseId;
        if (is_dir($caseDir)) {
            foreach (glob($caseDir . '/*.pdf') ?: [] as $pdfFile) {
                @unlink($pdfFile);
            }
            @rmdir($caseDir);
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);
        parent::tearDown();
    }

    private function attachDocument(DocumentType $type, string $filename, string $content = '%PDF-1.4 test'): Document
    {
        $relativeStoredPath = 'cases/' . $this->case->getId() . '/' . $filename;
        $absolutePath = $this->uploadsDir . '/' . $relativeStoredPath;

        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($absolutePath, $content);

        $doc = new Document();
        $doc->setLegalCase($this->case);
        $doc->setDocumentType($type);
        $doc->setOriginalFilename($filename);
        $doc->setStoredFilename($relativeStoredPath);
        $doc->setFileSize(strlen($content));
        $doc->setMimeType('application/pdf');
        $doc->setUploadedBy($this->user);
        $doc->setExtractionStatus(ExtractionStatus::COMPLETED);
        $this->em->persist($doc);
        $this->em->flush();
        $this->case->getDocuments()->add($doc);

        return $doc;
    }

    public function testPackageReturnsExistingZipPath(): void
    {
        $this->attachDocument(DocumentType::SOMATIE, 'somatie.pdf');

        $zipPath = $this->packager->package($this->case);

        self::assertFileExists($zipPath);
        self::assertStringContainsString('Pachet_', $zipPath);
        self::assertStringContainsString('.zip', $zipPath);
    }

    public function testPackageContainsNumberedTopLevelFiles(): void
    {
        $this->attachDocument(DocumentType::SOMATIE, 'somatie.pdf');
        $this->attachDocument(DocumentType::CERERE_OP, 'cerere_op.pdf');
        $this->attachDocument(DocumentType::OPIS, 'opis.pdf');

        $zipPath = $this->packager->package($this->case);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        self::assertContains('01_cerere_ordonanta_plata.pdf', $entries);
        self::assertContains('02_opis_documente.pdf', $entries);
        self::assertContains('03_somatie_de_plata.pdf', $entries);
    }

    /**
     * A top-level piece missing from storage used to be skipped in silence, so the
     * package went out without the proof of payment or of service while the petition
     * inside it spoke of both as annexed. Missing proof of service alone gets the
     * petition rejected as inadmissible, so this fails loudly instead.
     */
    public function testPackageRefusesToBuildWhenAMandatoryFileIsGoneFromStorage(): void
    {
        $this->attachDocument(DocumentType::CERERE_OP, 'cerere_op.pdf');
        $this->attachDocument(DocumentType::OPIS, 'opis.pdf');
        $proof = $this->attachDocument(DocumentType::DOVADA_TAXA_TIMBRU, 'dovada.pdf');

        unlink($this->uploadsDir . '/' . $proof->getStoredFilename());

        $this->expectException(MissingDocumentFileException::class);

        $this->packager->package($this->case);
    }

    /**
     * The proof that the summons was served is not at the top level of the archive,
     * but its absence is the one that costs most: without it the petition is rejected
     * as inadmissible, and no regularization term is granted for it.
     */
    public function testPackageRefusesToBuildWhenTheProofOfServiceIsGoneFromStorage(): void
    {
        $this->attachDocument(DocumentType::SOMATIE, 'somatie.pdf');
        $proof = $this->attachDocument(DocumentType::DOVADA_COMUNICARE, 'AR_12345.pdf');

        unlink($this->uploadsDir . '/' . $proof->getStoredFilename());

        $this->expectException(MissingDocumentFileException::class);

        $this->packager->package($this->case);
    }

    /**
     * An annex is a different matter: its absence is visible to the lawyer and does
     * not make the petition contradict itself, so the package still builds.
     */
    public function testPackageStillBuildsWhenAnAnnexFileIsGone(): void
    {
        $this->attachDocument(DocumentType::SOMATIE, 'somatie.pdf');
        $annex = $this->attachDocument(DocumentType::CONTRACT, 'Contract.pdf');

        unlink($this->uploadsDir . '/' . $annex->getStoredFilename());

        $zipPath = $this->packager->package($this->case);

        self::assertFileExists($zipPath);
    }

    public function testPackageGroupsAnnexesInAnexeFolder(): void
    {
        $this->attachDocument(DocumentType::SOMATIE, 'somatie.pdf');
        $this->attachDocument(DocumentType::CONTRACT, 'Contract.pdf');
        $this->attachDocument(DocumentType::FACTURA, 'Factura.pdf');

        $zipPath = $this->packager->package($this->case);

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        self::assertContains('anexe/Contract.pdf', $entries);
        self::assertContains('anexe/Factura.pdf', $entries);
    }

    public function testPackageGroupsDovadaComunicareInDedicatedFolder(): void
    {
        $this->attachDocument(DocumentType::SOMATIE, 'somatie.pdf');
        $this->attachDocument(DocumentType::DOVADA_COMUNICARE, 'AR_12345.pdf');

        $zipPath = $this->packager->package($this->case);

        $zip = new \ZipArchive();
        $zip->open($zipPath);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        self::assertContains('dovada_comunicare/AR_12345.pdf', $entries);
    }
}
