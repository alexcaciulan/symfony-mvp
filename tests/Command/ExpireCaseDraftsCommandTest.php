<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\DTO\Wizard\Step1CreditorData;
use App\Entity\CaseDraft;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Service\Wizard\CaseDraftPayloadCodec;
use App\Service\Wizard\WizardBag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ExpireCaseDraftsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CaseDraftPayloadCodec $codec;
    private User $user;
    private string $uploadsDir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->codec = static::getContainer()->get(CaseDraftPayloadCodec::class);
        $this->uploadsDir = static::getContainer()->getParameter('app.uploads_dir');

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail('expire-drafts-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM case_draft WHERE user_id = :id', ['id' => $userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);

        parent::tearDown();
    }

    public function testAnInactiveDraftGoesWithItsDocumentAndFileWhileARecentOneStays(): void
    {
        // Ids are read before the run: Doctrine clears the id of a removed entity.
        $oldId = $this->draft('-31 days')->getId();
        $oldDocument = $this->pendingDocument($this->em->find(CaseDraft::class, $oldId), '-31 days', ExtractionStatus::COMPLETED);
        $oldDocumentId = $oldDocument->getId();
        $oldPath = $this->uploadsDir . '/' . $oldDocument->getStoredFilename();
        $recent = $this->draft('-2 days');
        $recentDocument = $this->pendingDocument($recent, '-2 days', ExtractionStatus::COMPLETED);

        $tester = $this->tester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted 1 draft(s) with 1 document(s), 0 orphan upload(s).', $tester->getDisplay());
        $this->em->clear();
        self::assertNull($this->em->find(CaseDraft::class, $oldId));
        self::assertNull($this->em->find(Document::class, $oldDocumentId));
        self::assertFileDoesNotExist($oldPath);
        self::assertDirectoryDoesNotExist(dirname($oldPath));
        self::assertNotNull($this->em->find(CaseDraft::class, $recent->getId()));
        self::assertNotNull($this->em->find(Document::class, $recentDocument->getId()));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = ? AND user_id IS NULL',
            ['draft_expired', (string) $oldId],
        ), 'the sweeper acts on nobody\'s behalf');
    }

    public function testOldOrphanUploadsAreSweptUnlessStillBeingRead(): void
    {
        $oldOrphanId = $this->pendingDocument(null, '-40 days', ExtractionStatus::FAILED)->getId();
        $inFlight = $this->pendingDocument(null, '-40 days', ExtractionStatus::PROCESSING);
        $waiting = $this->pendingDocument(null, '-40 days', ExtractionStatus::PENDING_RETRY);
        $young = $this->pendingDocument(null, '-3 days', ExtractionStatus::FAILED);

        $tester = $this->tester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        self::assertNull($this->em->find(Document::class, $oldOrphanId));
        self::assertNotNull($this->em->find(Document::class, $inFlight->getId()));
        self::assertNotNull($this->em->find(Document::class, $waiting->getId()));
        self::assertNotNull($this->em->find(Document::class, $young->getId()));
    }

    public function testDryRunListsWithoutDeleting(): void
    {
        $old = $this->draft('-31 days');
        $oldDocument = $this->pendingDocument($old, '-31 days', ExtractionStatus::COMPLETED);
        $orphan = $this->pendingDocument(null, '-40 days', ExtractionStatus::FAILED);

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('Would delete draft #' . $old->getId(), $display);
        self::assertStringContainsString('Would delete document #' . $orphan->getId(), $display);
        self::assertStringContainsString('Dry run: 1 draft(s) with 1 document(s), 1 orphan upload(s).', $display);
        $this->em->clear();
        self::assertNotNull($this->em->find(CaseDraft::class, $old->getId()));
        self::assertNotNull($this->em->find(Document::class, $oldDocument->getId()));
        self::assertNotNull($this->em->find(Document::class, $orphan->getId()));
        self::assertFileExists($this->uploadsDir . '/' . $oldDocument->getStoredFilename());
    }

    public function testTheWindowIsAnOption(): void
    {
        $draftId = $this->draft('-5 days')->getId();

        $tester = $this->tester();
        $tester->execute(['--days' => '3']);

        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        self::assertNull($this->em->find(CaseDraft::class, $draftId));
    }

    public function testAZeroWindowIsRefused(): void
    {
        $tester = $this->tester();
        $tester->execute(['--days' => '0']);

        self::assertSame(2, $tester->getStatusCode());
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:drafts:expire'));
    }

    private function draft(string $lastActivity): CaseDraft
    {
        $bag = new WizardBag(creditor: new Step1CreditorData(name: 'Cedent SRL'));
        $draft = new CaseDraft($this->user);
        $draft->setPayload($this->codec->encode($bag), CaseDraftPayloadCodec::PAYLOAD_VERSION)->denormalizeFrom($bag, new \DateTimeImmutable($lastActivity));
        $this->em->persist($draft);
        $this->em->flush();

        return $draft;
    }

    private function pendingDocument(?CaseDraft $draft, string $createdAt, ExtractionStatus $status): Document
    {
        $relative = 'cases/_pending/' . $this->user->getId() . '/' . uniqid('expire-', true) . '/file.pdf';
        $absolute = $this->uploadsDir . '/' . $relative;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0o777, true);
        }
        file_put_contents($absolute, '%PDF-1.4 test');

        $document = new Document();
        $document->setUploadedBy($this->user);
        $document->setDraft($draft);
        $document->setDocumentType(DocumentType::FACTURA);
        $document->setOriginalFilename('file.pdf');
        $document->setStoredFilename($relative);
        $document->setMimeType('application/pdf');
        $document->setFileSize(13);
        $document->setExtractionStatus($status);
        (new \ReflectionProperty(Document::class, 'createdAt'))->setValue($document, new \DateTimeImmutable($createdAt));
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }
}
