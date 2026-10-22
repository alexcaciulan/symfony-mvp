<?php

declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\DTO\Document\PowerOfAttorneyDetails;
use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Service\Document\PowerOfAttorneyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PowerOfAttorneyServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PowerOfAttorneyService $service;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(PowerOfAttorneyService::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail('poa-svc-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
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
        $conn = $this->em->getConnection();
        $conn->executeStatement("DELETE FROM audit_log WHERE action = 'document_power_of_attorney_update' AND entity_id IN (SELECT id FROM document WHERE uploaded_by_id = ?)", [$userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);
        parent::tearDown();
    }

    private function document(DocumentType $type): Document
    {
        $doc = new Document();
        $doc->setLegalCase($this->case);
        $doc->setDocumentType($type);
        $doc->setOriginalFilename('act.pdf');
        $doc->setStoredFilename('stored-' . uniqid() . '.pdf');
        $doc->setFileSize(1024);
        $doc->setMimeType('application/pdf');
        $doc->setUploadedBy($this->user);
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    public function testUpdateDetailsStoresTrimmedValuesAndAuditsOldAndNew(): void
    {
        $doc = $this->document(DocumentType::IMPUTERNICIRE_AVOCATIALA);
        $doc->setPowerOfAttorneySeries('A');
        $this->em->flush();

        $this->service->updateDetails($doc, new PowerOfAttorneyDetails('  B  ', '   ', new \DateTimeImmutable('2026-03-10 15:30')));

        $docId = $doc->getId();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Document::class)->find($docId);
        self::assertSame('B', $reloaded->getPowerOfAttorneySeries());
        self::assertNull($reloaded->getPowerOfAttorneyNumber(), 'A blank number is stored as none.');
        self::assertSame('2026-03-10', $reloaded->getPowerOfAttorneyDate()?->format('Y-m-d'));

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'action' => 'document_power_of_attorney_update',
            'entityId' => (string) $docId,
        ]);
        self::assertNotNull($audit);
        // assertEquals: the JSON column does not keep key order.
        self::assertEquals(['powerOfAttorneySeries' => 'A', 'powerOfAttorneyNumber' => null, 'powerOfAttorneyDate' => null], $audit->getOldData());
        self::assertEquals(['powerOfAttorneySeries' => 'B', 'powerOfAttorneyNumber' => null, 'powerOfAttorneyDate' => '2026-03-10'], $audit->getNewData());
    }

    public function testUpdateDetailsRefusesAnotherDocumentType(): void
    {
        $doc = $this->document(DocumentType::FACTURA);

        $this->expectException(\LogicException::class);
        $this->service->updateDetails($doc, new PowerOfAttorneyDetails(null, null, new \DateTimeImmutable('2026-03-10')));
    }

    public function testDetailsStayEditableUntilThePetitionIsFiled(): void
    {
        foreach ([CaseStatus::AMIABIL, CaseStatus::SOMATIE_TRIMISA, CaseStatus::CERERE_GENERATA] as $status) {
            $this->case->setStatus($status);
            self::assertTrue($this->service->canEditDetails($this->case), $status->value);
        }
        foreach ([CaseStatus::CERERE_DEPUSA, CaseStatus::ORDONANTA_EMISA, CaseStatus::INCHIS_SUCCES] as $status) {
            $this->case->setStatus($status);
            self::assertFalse($this->service->canEditDetails($this->case), $status->value);
        }
    }
}
