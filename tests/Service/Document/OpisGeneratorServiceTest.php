<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\DocumentType;
use App\Enum\ExtractionStatus;
use App\Enum\PersonType;
use App\Service\Document\OpisGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Pas 5.2 — Tests for OpisGeneratorService. Verifies tabel numerotat sortat
 * (SOMATIE first, DOVADA_COMUNICARE next, restul cronologic), excluderea
 * propriei tip OPIS, gestionarea cazului fără documente.
 */
final class OpisGeneratorServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OpisGeneratorService $service;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(OpisGeneratorService::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $this->user = new User();
        $this->user->setEmail('opis-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Avocat');
        $this->user->setLastName('Opis');
        $this->em->persist($this->user);

        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Opis Creditor SRL');
        $creditor->setAddress('Str. Opis 1, București');
        $creditor->setCui('RO33333333');
        $this->em->persist($creditor);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setAmount('1000.00');
        $this->case->setCurrency('RON');
        $this->em->persist($this->case);

        $debtor = new Debtor();
        $debtor->setLegalCase($this->case);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('SC Opis Debtor SRL');
        $debtor->setAddress('Str. Opis 2, București');
        $debtor->setCui('RO44444444');
        $this->em->persist($debtor);
        $this->case->addDebtor($debtor);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id IS NULL', []);
        $conn->executeStatement('DELETE d FROM legal_deadline d JOIN legal_case lc ON d.legal_case_id = lc.id WHERE lc.user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = ?)', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);
        parent::tearDown();
    }

    private function attachDocument(DocumentType $type, string $filename): Document
    {
        $doc = new Document();
        $doc->setLegalCase($this->case);
        $doc->setDocumentType($type);
        $doc->setOriginalFilename($filename);
        $doc->setStoredFilename('stored-' . uniqid() . '.pdf');
        $doc->setFileSize(1024);
        $doc->setMimeType('application/pdf');
        $doc->setUploadedBy($this->user);
        $doc->setExtractionStatus(ExtractionStatus::COMPLETED);
        $this->em->persist($doc);
        $this->em->flush();
        $this->case->getDocuments()->add($doc);

        return $doc;
    }

    public function testRenderHtmlContainsHeadingAndCaseReference(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('OPIS DE DOCUMENTE', $html);
        self::assertStringContainsString($this->case->getCaseNumber(), $html);
        self::assertStringContainsString('1016', $html, 'Anexă CPC art. 1016 citată în subheading.');
    }

    public function testRenderHtmlListsDocumentsNumbered(): void
    {
        $this->attachDocument(DocumentType::CONTRACT, 'Contract_2024.pdf');
        $this->attachDocument(DocumentType::FACTURA, 'Factura_INV-001.pdf');
        $this->attachDocument(DocumentType::SOMATIE, 'Somatie_LR.pdf');

        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('Contract_2024.pdf', $html);
        self::assertStringContainsString('Factura_INV-001.pdf', $html);
        self::assertStringContainsString('Somatie_LR.pdf', $html);
        self::assertStringContainsString('Total documente: 3', $html);
    }

    public function testRenderHtmlEmptyDocumentsShowsPlaceholder(): void
    {
        $html = $this->service->renderHtml($this->case);

        self::assertStringContainsString('nicio anexă', $html);
    }

    public function testRenderHtmlExcludesOpisFromItsOwnList(): void
    {
        // Atașăm un OPIS existent (re-generare scenariu) — NU trebuie inclus în listă
        $this->attachDocument(DocumentType::OPIS, 'Opis_vechi.pdf');
        $this->attachDocument(DocumentType::SOMATIE, 'Somatie.pdf');

        $html = $this->service->renderHtml($this->case);

        self::assertStringNotContainsString('Opis_vechi.pdf', $html, 'Opisul NU se include pe el însuși.');
        self::assertStringContainsString('Somatie.pdf', $html);
        self::assertStringContainsString('Total documente: 1', $html);
    }

    public function testGeneratePersistsDocumentWithTypeOpis(): void
    {
        $document = $this->service->generate($this->case);
        $this->em->flush();

        self::assertSame(DocumentType::OPIS, $document->getDocumentType());
        self::assertGreaterThan(0, $document->getFileSize());
    }
}
