<?php

declare(strict_types=1);

namespace App\Tests\Service\Extraction;

use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Service\Extraction\ExtractionReuseService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ExtractionReuseServiceTest extends KernelTestCase
{
    private const HASH = 'f00d0000000000000000000000000000000000000000000000000000000000ab';

    private EntityManagerInterface $em;
    private ExtractionReuseService $service;
    private User $user;
    private User $other;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(ExtractionReuseService::class);
        $this->user = $this->makeUser('reuse-');
        $this->other = $this->makeUser('reuse-other-');
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ([$this->user, $this->other] as $user) {
            $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $user->getId()]);
            $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $user->getId()]);
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $user->getId()]);
        }
        $conn->executeStatement('DELETE FROM audit_log WHERE action = ? AND user_id IS NULL', ['extraction_reused']);

        parent::tearDown();
    }

    public function testAGenericUploadReusesACompletedReadingOfTheSameBytes(): void
    {
        $source = $this->document($this->user, DocumentType::FACTURA, ExtractionStatus::COMPLETED);

        self::assertSame($source->getId(), $this->service->findReusable($this->user, self::HASH, DocumentType::ALT_DOCUMENT)?->getId());
        self::assertSame($source->getId(), $this->service->findReusable($this->user, self::HASH, DocumentType::FACTURA)?->getId());
    }

    public function testADifferentDeclaredTypeIsNotReused(): void
    {
        $this->document($this->user, DocumentType::FACTURA, ExtractionStatus::COMPLETED);

        self::assertNull($this->service->findReusable($this->user, self::HASH, DocumentType::CONTRACT));
    }

    public function testAFailedOrUnfinishedReadingIsNotReused(): void
    {
        $this->document($this->user, DocumentType::FACTURA, ExtractionStatus::FAILED);
        $this->document($this->user, DocumentType::FACTURA, ExtractionStatus::PENDING);

        self::assertNull($this->service->findReusable($this->user, self::HASH, DocumentType::ALT_DOCUMENT));
    }

    public function testAnotherUsersReadingIsNeverReused(): void
    {
        $this->document($this->other, DocumentType::FACTURA, ExtractionStatus::COMPLETED);

        self::assertNull($this->service->findReusable($this->user, self::HASH, DocumentType::ALT_DOCUMENT));
        self::assertNull($this->service->findReusable($this->user, self::HASH, DocumentType::FACTURA));
    }

    public function testCopyingRewritesTheSourceIdAndPromotesTheDetectedType(): void
    {
        $source = $this->document($this->user, DocumentType::FACTURA, ExtractionStatus::COMPLETED);
        $target = $this->document($this->user, DocumentType::ALT_DOCUMENT, ExtractionStatus::PENDING, withData: false);

        $this->service->copyInto($source, $target);

        $this->em->clear();
        $fresh = $this->em->find(Document::class, $target->getId());
        self::assertSame(ExtractionStatus::COMPLETED, $fresh->getExtractionStatus());
        self::assertSame($target->getId(), $fresh->getExtractedData()['sourceDocumentId']);
        self::assertSame('Cedent SRL', $fresh->getExtractedData()['creditor']['name']);
        self::assertSame('0.90', $fresh->getExtractionConfidence());
        self::assertSame('ai_vision', $fresh->getExtractionStrategy());
        self::assertSame(DocumentType::FACTURA, $fresh->getDetectedType());
        self::assertSame('0.90', $fresh->getDetectedTypeConfidence());
        self::assertSame(DocumentType::FACTURA, $fresh->getDocumentType(), 'a confident detection replaces the generic type');
        self::assertNull($fresh->getLegalCase());
        self::assertNull($fresh->getDraft());
        self::assertNull($fresh->getExtractionFailureReason());

        $source = $this->em->find(Document::class, $source->getId());
        self::assertSame($source->getId(), $source->getExtractedData()['sourceDocumentId'], 'the source keeps its own id');

        $logs = $this->em->getRepository(AuditLog::class)->findBy(['action' => 'extraction_reused', 'entityId' => (string) $target->getId()]);
        self::assertCount(1, $logs);
        self::assertSame($source->getId(), $logs[0]->getNewData()['sourceDocumentId']);
    }

    public function testCopyingKeepsATypeTheLawyerDeclared(): void
    {
        $source = $this->document($this->user, DocumentType::FACTURA, ExtractionStatus::COMPLETED);
        $target = $this->document($this->user, DocumentType::FACTURA, ExtractionStatus::PENDING, withData: false);

        $this->service->copyInto($source, $target);

        self::assertSame(DocumentType::FACTURA, $target->getDocumentType());
        self::assertSame(ExtractionStatus::COMPLETED, $target->getExtractionStatus());
    }

    private function document(User $owner, DocumentType $type, ExtractionStatus $status, bool $withData = true): Document
    {
        $document = new Document();
        $document->setUploadedBy($owner);
        $document->setDocumentType($type);
        $document->setOriginalFilename('factura.pdf');
        $document->setStoredFilename('stored-' . uniqid() . '.pdf');
        $document->setMimeType('application/pdf');
        $document->setFileSize(10);
        $document->setContentHash(self::HASH);
        $document->setExtractionStatus($status);
        $this->em->persist($document);
        $this->em->flush();
        if ($withData) {
            $document->setExtractedData([
                'schemaVersion' => 2,
                'classification' => ['type' => 'factura', 'confidence' => 0.9, 'subtype' => null, 'rationale' => null],
                'sourceDocumentId' => $document->getId(),
                'strategy' => 'ai_vision',
                'globalConfidence' => 0.9,
                'creditor' => ['personType' => 'PJ', 'name' => 'Cedent SRL', 'cui' => '15193236', 'confidencePerField' => ['name' => 0.95, 'cui' => 0.95]],
            ]);
            $document->setExtractionConfidence('0.90');
            $document->setExtractionStrategy('ai_vision');
            $document->setDetectedType(DocumentType::FACTURA);
            $document->setDetectedTypeConfidence('0.90');
            $this->em->flush();
        }

        return $document;
    }

    private function makeUser(string $prefix): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail($prefix . uniqid() . '@test.com');
        $user->setPassword($hasher->hashPassword($user, 'password'));
        $user->setIsVerified(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
