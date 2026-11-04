<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\DTO\Case\LegalCostsInput;
use App\Entity\AuditLog;
use App\Entity\Creditor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\DocumentType;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\AuditLogService;
use App\Service\Case\ClaimEditRefused;
use App\Service\Case\LegalCostsEditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LegalCostsEditorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LegalCostsEditor $editor;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->editor = static::getContainer()->get(LegalCostsEditor::class);

        $this->user = new User();
        $this->user->setEmail('legal-costs-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $id = $this->user->getId();
        $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $id]);
        parent::tearDown();
    }

    #[DataProvider('editableStatuses')]
    public function testAmiableAndSummonsSentAcceptTheEdit(CaseStatus $status): void
    {
        $case = $this->makeCase($status);

        $change = $this->editor->update($case, new LegalCostsInput(1500.0, 'RON', 10.0), $this->user);

        self::assertFalse($change->isEmpty());
        self::assertSame('1500.00', $case->getLegalCostsFixed());
        self::assertSame('RON', $case->getLegalCostsCurrency());
        self::assertSame('10.00', $case->getLegalCostsSuccessPercent());
        $this->em->clear();
        self::assertSame('1500.00', $this->em->find(LegalCase::class, $case->getId())->getLegalCostsFixed());
    }

    /** @return iterable<string, array{CaseStatus}> */
    public static function editableStatuses(): iterable
    {
        yield 'amiable' => [CaseStatus::AMIABIL];
        yield 'summons sent' => [CaseStatus::SOMATIE_TRIMISA];
    }

    #[DataProvider('lockedStatuses')]
    public function testFromPetitionGeneratedOnTheEditIsRefused(CaseStatus $status): void
    {
        $case = $this->makeCase($status);

        try {
            $this->editor->update($case, new LegalCostsInput(1500.0, 'RON', 10.0), $this->user);
            self::fail('The petition is generated: the costs it quotes no longer change.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('legal_costs_locked', $refusal->reasonCode);
        }

        $this->em->clear();
        self::assertSame('500.00', $this->em->find(LegalCase::class, $case->getId())->getLegalCostsFixed());
        self::assertSame([], $this->audits($case));
    }

    /** @return iterable<string, array{CaseStatus}> */
    public static function lockedStatuses(): iterable
    {
        foreach ([CaseStatus::CERERE_GENERATA, CaseStatus::CERERE_DEPUSA, CaseStatus::DOSAR_INREGISTRAT, CaseStatus::INCHIS_SUCCES] as $status) {
            yield $status->value => [$status];
        }
    }

    public function testAStatusChangedUnderneathIsCaughtInsideTheLock(): void
    {
        $case = $this->makeCase(CaseStatus::SOMATIE_TRIMISA);
        $this->em->getConnection()->executeStatement(
            'UPDATE legal_case SET status = :status WHERE id = :id',
            ['status' => CaseStatus::CERERE_GENERATA->value, 'id' => $case->getId()],
        );

        try {
            $this->editor->update($case, new LegalCostsInput(1500.0, 'RON', null), $this->user);
            self::fail('The status read under the lock must refuse the edit.');
        } catch (ClaimEditRefused $refusal) {
            self::assertSame('legal_costs_locked', $refusal->reasonCode);
        }

        self::assertTrue($this->em->isOpen(), 'A refusal keeps the manager usable.');
        self::assertSame(CaseStatus::CERERE_GENERATA, $case->getStatus());
        self::assertSame('500.00', $case->getLegalCostsFixed(), 'The entity is reloaded as the row is.');
    }

    public function testAnExistingPetitionIsMarkedOutdated(): void
    {
        $case = $this->makeCase(CaseStatus::SOMATIE_TRIMISA);
        $petition = $this->document($case, DocumentType::CERERE_OP);

        $change = $this->editor->update($case, new LegalCostsInput(800.0, 'EUR', null), $this->user);

        self::assertTrue($change->paymentOrderMarkedOutdated);
        self::assertTrue($petition->isOutdated());
        self::assertTrue($this->audits($case)[0]->getNewData()['paymentOrderOutdated']);
    }

    public function testNoChangeWritesNoAudit(): void
    {
        $case = $this->makeCase(CaseStatus::AMIABIL);
        $petition = $this->document($case, DocumentType::CERERE_OP);

        $change = $this->editor->update($case, new LegalCostsInput(500.0, 'EUR', null), $this->user);

        self::assertTrue($change->isEmpty());
        self::assertFalse($change->paymentOrderMarkedOutdated);
        self::assertFalse($petition->isOutdated());
        self::assertSame([], $this->audits($case));
    }

    public function testTheSummonsIsNotTouched(): void
    {
        $case = $this->makeCase(CaseStatus::SOMATIE_TRIMISA);
        $summons = $this->document($case, DocumentType::SOMATIE);

        $this->editor->update($case, new LegalCostsInput(900.0, 'RON', 5.0), $this->user);

        self::assertFalse($summons->isOutdated(), 'The summons quotes no recovery costs.');
    }

    public function testAuditHoldsBeforeAndAfter(): void
    {
        $case = $this->makeCase(CaseStatus::AMIABIL);

        $this->editor->update($case, new LegalCostsInput(1500.0, 'RON', 10.0), $this->user);

        $entries = $this->audits($case);
        self::assertCount(1, $entries);
        $entry = $entries[0];
        self::assertSame(AuditLogService::CATEGORY_CLAIM_EDITED, $entry->getCategory());
        self::assertSame($this->user->getId(), $entry->getUser()?->getId());
        self::assertSame(['legalCostsFixed' => '500.00', 'legalCostsCurrency' => 'EUR', 'legalCostsSuccessPercent' => null], $entry->getOldData());
        self::assertSame('1500.00', $entry->getNewData()['legalCostsFixed']);
        self::assertSame('RON', $entry->getNewData()['legalCostsCurrency']);
        self::assertSame('10.00', $entry->getNewData()['legalCostsSuccessPercent']);
        self::assertSame('AMIABIL', $entry->getNewData()['status']);
        self::assertFalse($entry->getNewData()['paymentOrderOutdated']);
    }

    private function makeCase(CaseStatus $status): LegalCase
    {
        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Costs Creditor SRL');
        $creditor->setAddress('Str. Costs 1, Cluj-Napoca');
        $creditor->setCui('RO12345678');
        $this->em->persist($creditor);

        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCreditor($creditor);
        $case->setStatus($status);
        $case->setRelationshipType(RelationshipType::COMERCIAL);
        $case->setAmount('1000.00');
        $case->setCurrency('RON');
        $case->setLegalCostsFixed('500.00');
        $case->setLegalCostsCurrency('EUR');
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }

    private function document(LegalCase $case, DocumentType $type): Document
    {
        $document = new Document();
        $document->setLegalCase($case);
        $document->setDocumentType($type);
        $document->setOriginalFilename($type->value . '.pdf');
        $document->setStoredFilename('cases/' . $case->getId() . '/' . uniqid() . '.pdf');
        $document->setFileSize(10);
        $document->setMimeType('application/pdf');
        $document->setUploadedBy($this->user);
        $case->addDocument($document);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    /** @return list<AuditLog> */
    private function audits(LegalCase $case): array
    {
        return $this->em->getRepository(AuditLog::class)->findBy(
            ['entityId' => (string) $case->getId(), 'action' => LegalCostsEditor::ACTION],
            ['id' => 'ASC'],
        );
    }
}
