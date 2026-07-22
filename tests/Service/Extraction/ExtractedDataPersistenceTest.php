<?php

namespace App\Tests\Service\Extraction;

use App\DTO\Extraction\ClaimExtraction;
use App\DTO\Extraction\CreditorExtraction;
use App\DTO\Extraction\ExtractedDocumentData;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\LegalGroundCategory;
use App\Enum\PersonType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Doctrine round-trip on the `Document.extractedData` JSON column. The
 * cascade integration tests assert the orchestrator's in-memory output, but
 * none of them clear the EntityManager and re-fetch — so a serialization bug
 * (driver mishandling unicode, MySQL JSON column rejecting a value, etc.)
 * would slip through. This test forces a real DB round-trip with a payload
 * containing the patterns most likely to cause encoding accidents:
 *   - Embedded double quotes and backslashes
 *   - Romanian diacritics + em-dash (UTF-8 multi-byte)
 *   - A null sub-DTO (`debtor`)
 *   - Nested confidencePerField map
 *   - DateTimeImmutable serialized to ISO-8601
 *   - Backed enum value preserved as its string form
 */
class ExtractedDataPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $testEmail;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->testEmail = 'extracted-data-rt-' . uniqid() . '@test.com';
    }

    protected function tearDown(): void
    {
        // FK-aware cleanup order: Document → LegalCase → User. Use DQL DELETE so
        // we don't have to rely on cascade={"remove"} being configured (which
        // varies across entities — see CLAUDE.md test conventions).
        $this->em->createQuery(
            'DELETE FROM App\Entity\Document d WHERE d.legalCase IN '
                . '(SELECT c FROM App\Entity\LegalCase c WHERE c.user IN '
                . '(SELECT u FROM App\Entity\User u WHERE u.email = :email))',
        )->setParameter('email', $this->testEmail)->execute();

        $this->em->createQuery(
            'DELETE FROM App\Entity\LegalCase c WHERE c.user IN '
                . '(SELECT u FROM App\Entity\User u WHERE u.email = :email)',
        )->setParameter('email', $this->testEmail)->execute();

        $this->em->createQuery('DELETE FROM App\Entity\User u WHERE u.email = :email')
            ->setParameter('email', $this->testEmail)
            ->execute();
    }

    public function testExtractedDataJsonColumnPreservesUnicodeSpecialCharsAndNullSubDto(): void
    {
        $user = new User();
        $user->setEmail($this->testEmail);
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
        $document->setOriginalFilename('round-trip.pdf');
        $document->setStoredFilename('cases/round-trip.pdf');
        $document->setFileSize(2048);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($user);

        $extracted = new ExtractedDocumentData(
            sourceDocumentId: 0,
            strategy: 'pdf_parser',
            globalConfidence: 0.85,
            extractedAt: new \DateTimeImmutable('2026-05-10T14:30:00+00:00'),
            creditor: new CreditorExtraction(
                personType: PersonType::PJ,
                name: 'SC "Test \\ Corp" SRL — română Ăăâî',
                cui: '15193236',
                isVatPayer: true,
                confidencePerField: ['name' => 0.95, 'cui' => 0.99, 'iban' => 0.0],
            ),
            debtors: [], // exercise the empty-list branch in toArray
            claim: new ClaimExtraction(
                amount: 6009.50,
                currency: 'RON',
                dueDate: new \DateTimeImmutable('2026-06-15'),
                legalGround: LegalGroundCategory::FACTURA_ACCEPTATA,
                description: "Factură 2026/142 — servicii IT",
                confidencePerField: ['amount' => 0.99, 'dueDate' => 0.93],
            ),
            rawOcrText: null,
        );
        $document->setExtractedData($extracted->toArray());
        $document->setExtractionStatus(ExtractionStatus::COMPLETED);
        $document->setExtractionStrategy('pdf_parser');
        $document->setExtractionConfidence('0.85');

        $this->em->persist($document);
        $this->em->flush();
        $documentId = $document->getId();
        $this->assertNotNull($documentId);

        // Force a real DB round-trip — clear so the next find() fetches from MySQL,
        // not from the EM identity map.
        $this->em->clear();

        $refetched = $this->em->find(Document::class, $documentId);
        $this->assertNotNull($refetched, 'Document must be re-fetchable post-flush');

        $persisted = $refetched->getExtractedData();
        $this->assertIsArray($persisted);

        // Unicode + special chars survived encode/decode.
        $this->assertSame('SC "Test \\ Corp" SRL — română Ăăâî', $persisted['creditor']['name']);
        $this->assertSame('Factură 2026/142 — servicii IT', $persisted['claim']['description']);

        // Numeric / structural fidelity.
        $this->assertSame('15193236', $persisted['creditor']['cui']);
        $this->assertTrue($persisted['creditor']['isVatPayer']);
        // assertEquals (NOT assertSame) — MySQL's JSON storage may reorder
        // associative-array keys on round-trip, which is fine for our consumers
        // (they look up by key, not by position). What matters is the value
        // fidelity, including the explicit-zero edge case.
        $this->assertEqualsCanonicalizing(['name' => 0.95, 'cui' => 0.99, 'iban' => 0.0], $persisted['creditor']['confidencePerField']);
        $this->assertSame(6009.50, $persisted['claim']['amount']);

        // Null sub-DTO preserved as null (toArray emits 'debtor' => null).
        $this->assertArrayHasKey('debtor', $persisted);
        $this->assertNull($persisted['debtor']);

        // Backed enum preserved as its string value, not enum object.
        $this->assertSame('FACTURA_ACCEPTATA', $persisted['claim']['legalGround']);

        // DateTime serialized to ISO format and round-tripped intact.
        $this->assertIsString($persisted['claim']['dueDate']);
        $this->assertNotFalse(strtotime($persisted['claim']['dueDate']));
        $this->assertSame('2026-06-15', substr($persisted['claim']['dueDate'], 0, 10));

        // Top-level metadata preserved.
        $this->assertSame('pdf_parser', $persisted['strategy']);
        $this->assertSame(0.85, $persisted['globalConfidence']);

        // Status + strategy persisted on the entity itself, alongside the JSON blob.
        $this->assertSame(ExtractionStatus::COMPLETED, $refetched->getExtractionStatus());
        $this->assertSame('pdf_parser', $refetched->getExtractionStrategy());
    }
}
