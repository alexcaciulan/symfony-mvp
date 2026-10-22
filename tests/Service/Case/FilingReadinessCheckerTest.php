<?php

declare(strict_types=1);

namespace App\Tests\Service\Case;

use App\DTO\Case\FilingIssue;
use App\Entity\ClaimItem;
use App\Entity\Creditor;
use App\Entity\Document;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\ClaimItemKind;
use App\Enum\DocumentType;
use App\Enum\FilingIssueCode;
use App\Enum\PersonType;
use App\Service\Case\FilingReadinessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The checker reads the case as Doctrine loads it, so every scenario is persisted
 * and the case reloaded from a cleared entity manager before the check.
 */
final class FilingReadinessCheckerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FilingReadinessChecker $checker;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->checker = $container->get(FilingReadinessChecker::class);

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $this->user = new User();
        $this->user->setEmail('filing-ready-' . uniqid() . '@test.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'password'));
        $this->user->setIsVerified(true);
        $this->user->setFirstName('Avocat');
        $this->user->setLastName('Filing');
        $this->em->persist($this->user);

        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('SC Filing Creditor SRL');
        $creditor->setAddress('Str. Filing 1, București');
        $creditor->setCui('RO55555555');
        $this->em->persist($creditor);

        $this->case = new LegalCase();
        $this->case->setUser($this->user);
        $this->case->setCreditor($creditor);
        $this->case->setAmount('1000.00');
        $this->case->setCurrency('RON');
        $this->case->setPaymentNoticeDate(new \DateTime('2026-02-01'));
        $this->em->persist($this->case);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $userId = $this->user->getId();
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE ci FROM claim_item ci JOIN legal_case lc ON ci.legal_case_id = lc.id WHERE lc.user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM document WHERE uploaded_by_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = ?', [$userId]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = ?', [$userId]);
        parent::tearDown();
    }

    private function document(DocumentType $type, ?callable $configure = null): Document
    {
        $doc = new Document();
        $doc->setLegalCase($this->case);
        $doc->setDocumentType($type);
        $doc->setOriginalFilename($type->value . '.pdf');
        $doc->setStoredFilename('stored-' . uniqid() . '.pdf');
        $doc->setFileSize(1024);
        $doc->setMimeType('application/pdf');
        $doc->setUploadedBy($this->user);
        if ($configure !== null) {
            $configure($doc);
        }
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    private function powerOfAttorney(?string $date = '2026-01-15'): Document
    {
        return $this->document(DocumentType::IMPUTERNICIRE_AVOCATIALA, static function (Document $doc) use ($date): void {
            $doc->setPowerOfAttorneyDate($date !== null ? new \DateTimeImmutable($date) : null);
        });
    }

    private function claimItem(?Document $source, bool $confirmed = true): void
    {
        $item = new ClaimItem();
        $item->setLegalCase($this->case);
        $item->setKind($source?->getDocumentType() === DocumentType::CONTRACT ? ClaimItemKind::CONTRACT_INSTALMENT : ClaimItemKind::OTHER);
        $item->setAmount('500.00');
        $item->setAmountRon('500.00');
        $item->setCurrency('RON');
        $item->setDueDate(new \DateTimeImmutable('2025-06-01'));
        $item->setConfirmedByLawyer($confirmed);
        $item->setDedupKey('k:' . uniqid());
        $item->setSourceDocument($source);
        $this->em->persist($item);
        $this->em->flush();
    }

    private function check(): \App\DTO\Case\FilingReadiness
    {
        $caseId = $this->case->getId();
        $this->em->clear();
        $reloaded = $this->em->getRepository(LegalCase::class)->find($caseId);
        self::assertNotNull($reloaded);

        return $this->checker->check($reloaded);
    }

    /**
     * @param list<FilingIssue> $issues
     * @return list<string>
     */
    private static function codes(array $issues): array
    {
        return array_map(static fn (FilingIssue $issue): string => $issue->code->value, $issues);
    }

    public function testACaseWithoutThePowerOfAttorneyIsBlocked(): void
    {
        $this->document(DocumentType::FACTURA);

        $readiness = $this->check();

        self::assertFalse($readiness->isReady());
        self::assertSame(['power_of_attorney_missing'], self::codes($readiness->blockers));
    }

    public function testAnUndatedPowerOfAttorneyBlocksAndPointsToItself(): void
    {
        $this->document(DocumentType::FACTURA);
        $poa = $this->powerOfAttorney(null);

        $readiness = $this->check();

        self::assertSame(FilingIssueCode::POWER_OF_ATTORNEY_DATE_MISSING, $readiness->firstBlocker()?->code);
        self::assertSame($poa->getId(), $readiness->firstBlocker()?->documentId);
    }

    public function testAnUndatedDuplicateOnlyWarnsWhenADatedActIsOnFile(): void
    {
        $this->document(DocumentType::FACTURA);
        $this->powerOfAttorney('2026-01-10');
        $duplicate = $this->powerOfAttorney(null);

        $readiness = $this->check();

        self::assertTrue($readiness->isReady());
        self::assertTrue($readiness->hasWarning(FilingIssueCode::POWER_OF_ATTORNEY_EXTRA_DATE_MISSING));
        $extra = array_values(array_filter($readiness->warnings, static fn (FilingIssue $i): bool => $i->code === FilingIssueCode::POWER_OF_ATTORNEY_EXTRA_DATE_MISSING));
        self::assertSame($duplicate->getId(), $extra[0]->documentId);
    }

    public function testAnInvoiceAndADatedPowerOfAttorneyMakeTheCaseReady(): void
    {
        $this->document(DocumentType::FACTURA);
        $this->powerOfAttorney();

        self::assertTrue($this->check()->isReady());
    }

    public function testABalanceConfirmationProvesTheClaim(): void
    {
        $this->document(DocumentType::CONFIRMARE_SOLD);
        $this->powerOfAttorney();

        self::assertTrue($this->check()->isReady());
    }

    /** Contract instalments rest on the contract the claim position names as its source. */
    public function testAContractBehindACountingClaimPositionProvesTheClaim(): void
    {
        $this->powerOfAttorney();
        $this->claimItem($this->document(DocumentType::CONTRACT));

        self::assertTrue($this->check()->isReady());
    }

    public function testAContractNoCountingPositionPointsToDoesNotProveTheClaim(): void
    {
        $this->powerOfAttorney();
        $this->claimItem($this->document(DocumentType::CONTRACT), confirmed: false);

        self::assertTrue($this->check()->hasBlocker(FilingIssueCode::CLAIM_DOCUMENT_MISSING));
    }

    /** Art. 1014 CPC: a contract may be the only act the claim rests on (a fixed price never invoiced). */
    public function testAContractNextToAManualCountingPositionProvesTheClaim(): void
    {
        $this->powerOfAttorney();
        $this->document(DocumentType::CONTRACT);
        $this->claimItem(null);

        $readiness = $this->check();

        self::assertTrue($readiness->isReady());
        self::assertSame([], $readiness->warnings);
    }

    public function testAContractWithoutAnyClaimPositionDoesNotProveTheClaim(): void
    {
        $this->powerOfAttorney();
        $this->document(DocumentType::CONTRACT);

        self::assertSame(['claim_document_missing'], self::codes($this->check()->blockers));
    }

    public function testManualClaimPositionsWithoutADocumentDoNotProveTheClaim(): void
    {
        $this->powerOfAttorney();
        $this->claimItem(null);

        $readiness = $this->check();

        self::assertSame(['claim_document_missing'], self::codes($readiness->blockers));
    }

    public function testAGenericUploadConfidentlyDetectedAsAnInvoiceProvesTheClaim(): void
    {
        $this->powerOfAttorney();
        $this->document(DocumentType::ALT_DOCUMENT, static function (Document $doc): void {
            $doc->setDetectedType(DocumentType::FACTURA);
            $doc->setDetectedTypeConfidence('0.90');
        });

        self::assertTrue($this->check()->isReady());
    }

    public function testAGenericUploadWithALowConfidenceDetectionDoesNotProveTheClaim(): void
    {
        $this->powerOfAttorney();
        $this->document(DocumentType::ALT_DOCUMENT, static function (Document $doc): void {
            $doc->setDetectedType(DocumentType::FACTURA);
            $doc->setDetectedTypeConfidence('0.40');
        });

        self::assertTrue($this->check()->hasBlocker(FilingIssueCode::CLAIM_DOCUMENT_MISSING));
    }

    public function testGenericAttachmentsAndProofsDoNotProveTheClaim(): void
    {
        $this->powerOfAttorney();
        $this->document(DocumentType::ANEXA);
        $this->document(DocumentType::DOVADA);
        $this->document(DocumentType::DOVADA_COMUNICARE);
        $this->document(DocumentType::SOMATIE_ANTERIOARA);

        self::assertTrue($this->check()->hasBlocker(FilingIssueCode::CLAIM_DOCUMENT_MISSING));
    }

    public function testAMissingContractOnlyWarns(): void
    {
        $this->document(DocumentType::FACTURA);
        $this->powerOfAttorney();

        $readiness = $this->check();

        self::assertTrue($readiness->isReady());
        self::assertSame(['contract_missing'], self::codes($readiness->warnings));
    }

    public function testAnUploadedContractClearsTheContractWarning(): void
    {
        $this->document(DocumentType::FACTURA);
        $this->document(DocumentType::CONTRACT);
        $this->powerOfAttorney();

        self::assertSame([], $this->check()->warnings);
    }

    public function testAPowerOfAttorneyDatedTheSummonsDayDoesNotWarn(): void
    {
        $this->document(DocumentType::FACTURA);
        $this->document(DocumentType::CONTRACT);
        $this->powerOfAttorney('2026-02-01');

        self::assertFalse($this->check()->hasWarning(FilingIssueCode::POWER_OF_ATTORNEY_AFTER_SUMMONS));
    }

    public function testAPowerOfAttorneyDatedAfterTheSummonsWarnsWithBothDates(): void
    {
        $this->document(DocumentType::FACTURA);
        $this->document(DocumentType::CONTRACT);
        $poa = $this->powerOfAttorney('2026-02-02');

        $readiness = $this->check();

        self::assertTrue($readiness->isReady(), 'A later mandate may be ratified, so it warns and never blocks.');
        self::assertCount(1, $readiness->warnings);
        $warning = $readiness->warnings[0];
        self::assertSame(FilingIssueCode::POWER_OF_ATTORNEY_AFTER_SUMMONS, $warning->code);
        self::assertSame(['%poa_date%' => '02.02.2026', '%summons_date%' => '01.02.2026'], $warning->params);
        self::assertSame($poa->getId(), $warning->documentId);
    }

    public function testNoSummonsDateMeansNoComparison(): void
    {
        $this->case->setPaymentNoticeDate(null);
        $this->case->setPaymentNoticeCommunicationDate(new \DateTimeImmutable('2026-01-01'));
        $this->em->flush();
        $this->document(DocumentType::FACTURA);
        $this->document(DocumentType::CONTRACT);
        $this->powerOfAttorney('2026-02-02');

        self::assertSame([], $this->check()->warnings, 'The debtor receipt date is not the summons date.');
    }
}
