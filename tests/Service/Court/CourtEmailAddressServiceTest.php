<?php

declare(strict_types=1);

namespace App\Tests\Service\Court;

use App\Entity\AuditLog;
use App\Entity\Court;
use App\Entity\CourtEmailAddress;
use App\Entity\LegalCase;
use App\Entity\User;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailReportReason;
use App\Enum\CourtEmailSource;
use App\Enum\CourtEmailStatus;
use App\Service\AuditLogService;
use App\Service\Court\CourtEmailActionRefusedException;
use App\Service\Court\CourtEmailAddressService;
use App\Tests\Support\FilingCaseFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CourtEmailAddressServiceTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;

    private EntityManagerInterface $em;
    private CourtEmailAddressService $service;
    private User $user;
    private LegalCase $case;
    private Court $court;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->service = static::getContainer()->get(CourtEmailAddressService::class);
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'court-email-svc');
        $this->case = $this->createFilingCase($this->em, $this->user);
        $this->court = $this->case->getCourt();
    }

    protected function tearDown(): void
    {
        if (isset($this->court)) {
            $this->em->getConnection()->executeStatement(
                "DELETE FROM audit_log WHERE entity_type = ? AND JSON_EXTRACT(new_data, '$.courtId') = ?",
                [CourtEmailAddress::class, $this->court->getId()],
            );
        }
        $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        parent::tearDown();
    }

    public function testValidateWithoutTheStatementIsRefusedAndChangesNothing(): void
    {
        $address = $this->proposed('registratura@just.ro');

        $this->assertRefused('court_address.validation.error.statement_required', fn () => $this->service->validate($address, $this->user, false));

        $this->em->refresh($address);
        self::assertSame(CourtEmailStatus::PROPOSED, $address->getStatus());
        self::assertNull($address->getValidatedAt());
    }

    public function testValidateIsRefusedOnPersonalRejectedAndInvalidAddresses(): void
    {
        $personal = $this->createCourtEmailAddress($this->em, $this->court, 'ion.popescu@just.ro', CourtEmailPurpose::PERSONAL);
        $rejected = $this->createCourtEmailAddress($this->em, $this->court, 'a@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::REJECTED);
        $invalid = $this->createCourtEmailAddress($this->em, $this->court, 'b@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::INVALID);

        foreach ([$personal, $rejected, $invalid] as $address) {
            $this->assertRefused('court_address.validation.error.not_allowed_state', fn () => $this->service->validate($address, $this->user, true));
            self::assertNotSame(CourtEmailStatus::VALIDATED, $address->getStatus());
        }
    }

    public function testValidatePromotesTheAddressKeepsTheStatementTextAndAudits(): void
    {
        $address = $this->proposed('registratura@just.ro');

        $this->service->validate($address, $this->user, true, $this->case);

        $this->em->clear();
        $address = $this->em->find(CourtEmailAddress::class, $address->getId());
        self::assertSame(CourtEmailStatus::VALIDATED, $address->getStatus());
        self::assertSame($this->user->getId(), $address->getValidatedBy()?->getId());
        self::assertNotNull($address->getValidatedAt());
        self::assertSame(
            'Confirm că la această adresă instanța primește cereri introductive, inclusiv cereri de ordonanță de plată, pe cale electronică.',
            $address->getValidationStatement(),
        );
        self::assertTrue($address->isFilingDefault());

        $audit = $this->auditRows($address->getId());
        self::assertCount(1, $audit);
        self::assertSame(AuditLogService::CATEGORY_COURT_EMAIL, $audit[0]->getCategory());
        self::assertSame('court_email_validated', $audit[0]->getAction());
        self::assertSame($this->case->getId(), $audit[0]->getNewData()['caseId']);
    }

    public function testSettingASecondDefaultClearsTheFirstOfTheSamePurpose(): void
    {
        $first = $this->proposed('registratura@just.ro');
        $second = $this->proposed('registratura2@just.ro');
        $this->service->validate($first, $this->user, true);
        $this->service->validate($second, $this->user, true);
        self::assertTrue($first->isFilingDefault());
        self::assertFalse($second->isFilingDefault(), 'Validation sets a default only when none exists.');

        $this->service->setFilingDefault($second);

        $this->em->refresh($first);
        $this->em->refresh($second);
        self::assertFalse($first->isFilingDefault());
        self::assertTrue($second->isFilingDefault());
    }

    public function testAddManualRefusesBadInputAndCreatesAProposedManualAddress(): void
    {
        $this->assertRefused('court_address.validation.error.invalid_format', fn () => $this->service->addManual($this->court, 'not-an-email', CourtEmailPurpose::REGISTRY));
        $this->assertRefused('court_address.validation.error.personal_refused', fn () => $this->service->addManual($this->court, 'ion.popescu@just.ro', CourtEmailPurpose::REGISTRY));
        $this->assertRefused('court_address.validation.error.personal_refused', fn () => $this->service->addManual($this->court, 'registratura@just.ro', CourtEmailPurpose::PERSONAL));
        self::assertSame(0, $this->addressCount());

        $address = $this->service->addManual($this->court, ' Registratura@Just.ro ', CourtEmailPurpose::REGISTRY, $this->case);

        self::assertSame('registratura@just.ro', $address->getEmail());
        self::assertSame(CourtEmailStatus::PROPOSED, $address->getStatus());
        self::assertSame(CourtEmailSource::MANUAL, $address->getSource());
        self::assertNull($address->getValidatedAt());

        $this->assertRefused('court_address.validation.error.duplicate', fn () => $this->service->addManual($this->court, 'REGISTRATURA@just.ro', CourtEmailPurpose::SECTION_CIVIL));
        self::assertSame(1, $this->addressCount());
    }

    public function testReportWrongInvalidatesAConfirmedAddressAndRejectsAProposalAndReopenClearsBoth(): void
    {
        $validated = $this->proposed('registratura@just.ro');
        $this->service->validate($validated, $this->user, true);
        $proposed = $this->proposed('civil@just.ro', CourtEmailPurpose::SECTION_CIVIL);

        $this->service->reportWrong($validated, CourtEmailReportReason::BOUNCED, $this->case);
        $this->service->reportWrong($proposed, CourtEmailReportReason::WRONG_COURT, $this->case);

        self::assertSame(CourtEmailStatus::INVALID, $validated->getStatus());
        self::assertSame('reported_by_lawyer:BOUNCED', $validated->getInvalidReason());
        self::assertFalse($validated->isFilingDefault());
        self::assertNotNull($validated->getValidatedAt(), 'The confirmation stays as the record of who relied on it.');
        self::assertSame(CourtEmailStatus::REJECTED, $proposed->getStatus());

        $this->service->reopen($validated);

        self::assertSame(CourtEmailStatus::PROPOSED, $validated->getStatus());
        self::assertNull($validated->getValidatedAt());
        self::assertNull($validated->getValidatedBy());
        self::assertNull($validated->getValidationStatement());
        self::assertNull($validated->getInvalidatedAt());
        self::assertNull($validated->getInvalidReason());
    }

    public function testErasePersonalRemovesTheRowAndTheAddressFromEarlierAuditPayloads(): void
    {
        $address = $this->proposed('ana.marin@just.ro');
        $this->service->validate($address, $this->user, true);
        $this->service->changePurpose($address, CourtEmailPurpose::PERSONAL, null);
        self::assertSame(CourtEmailStatus::PROPOSED, $address->getStatus());
        $id = $address->getId();

        $this->service->erasePersonal($address);

        $this->em->clear();
        self::assertNull($this->em->find(CourtEmailAddress::class, $id));
        $rows = $this->auditRows($id);
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertStringNotContainsString('ana.marin', (string) json_encode([$row->getOldData(), $row->getNewData()]));
        }
        $erased = end($rows);
        self::assertSame('court_email_personal_erased', $erased->getAction());
        self::assertSame(hash('sha256', 'ana.marin@just.ro'), $erased->getNewData()['sha256']);
    }

    public function testChangingThePurposeOfAConfirmedAddressSendsItBackToProposed(): void
    {
        $address = $this->proposed('registratura@just.ro');
        $this->service->validate($address, $this->user, true);
        self::assertTrue($address->isFilingDefault());

        $this->service->changePurpose($address, CourtEmailPurpose::SECTION_PROFESSIONALS, ' Secția a VI-a ');

        $this->em->clear();
        $address = $this->em->find(CourtEmailAddress::class, $address->getId());
        self::assertSame(CourtEmailPurpose::SECTION_PROFESSIONALS, $address->getPurpose());
        self::assertSame('Secția a VI-a', $address->getSectionLabel());
        self::assertSame(CourtEmailStatus::PROPOSED, $address->getStatus());
        self::assertFalse($address->isFilingDefault());
        self::assertNull($address->getValidatedAt());
        self::assertNull($address->getValidatedBy());
        self::assertNull($address->getValidationStatement());
        $audit = $this->auditRows($address->getId());
        self::assertSame('court_email_purpose_changed', end($audit)->getAction());
    }

    public function testChangingOnlyTheSectionLabelKeepsTheConfirmation(): void
    {
        $address = $this->proposed('civil@just.ro', CourtEmailPurpose::SECTION_CIVIL);
        $this->service->validate($address, $this->user, true);

        $this->service->changePurpose($address, CourtEmailPurpose::SECTION_CIVIL, 'Secția I civilă');

        self::assertSame(CourtEmailStatus::VALIDATED, $address->getStatus());
        self::assertTrue($address->isFilingDefault());
        self::assertSame('Secția I civilă', $address->getSectionLabel());
    }

    public function testSetFilingDefaultIsRefusedOnAProposedOrAnExpiredAddress(): void
    {
        $proposed = $this->proposed('registratura@just.ro');
        $expired = $this->createCourtEmailAddress($this->em, $this->court, 'registratura2@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-200 days'), $this->user);

        foreach ([$proposed, $expired] as $address) {
            $this->assertRefused('court_address.validation.error.not_allowed_state', fn () => $this->service->setFilingDefault($address));
            $this->em->refresh($address);
            self::assertFalse($address->isFilingDefault());
        }
    }

    public function testAnExpiredManualAddressMissingFromItsSourceCanBeReconfirmed(): void
    {
        $address = $this->service->addManual($this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY);
        $address->markValidated($this->user, new \DateTimeImmutable('-200 days'), 'statement');
        $address->setMissingSince(new \DateTimeImmutable('-3 days'));
        $this->em->flush();

        $this->service->validate($address, $this->user, true, $this->case);

        $this->em->clear();
        $address = $this->em->find(CourtEmailAddress::class, $address->getId());
        self::assertSame(CourtEmailStatus::VALIDATED, $address->getStatus());
        self::assertNull($address->getMissingSince());
        self::assertTrue($address->isUsableForFiling(new \DateTimeImmutable()));
        self::assertGreaterThan(new \DateTimeImmutable('-1 day'), $address->getValidatedAt());
        $audit = $this->auditRows($address->getId());
        self::assertSame('court_email_revalidated', end($audit)->getAction());
    }

    public function testAnImportedAddressMissingFromItsSourceCannotBeReconfirmed(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-10 days'), $this->user);
        $missingSince = new \DateTimeImmutable('-3 days');
        $address->setMissingSince($missingSince);
        $this->em->flush();
        $validatedAt = $address->getValidatedAt()?->format('Y-m-d H:i:s');

        $this->assertRefused('court_address.validation.error.missing_on_source', fn () => $this->service->validate($address, $this->user, true));

        $this->em->refresh($address);
        self::assertNotNull($address->getMissingSince());
        self::assertSame($validatedAt, $address->getValidatedAt()?->format('Y-m-d H:i:s'));
        self::assertSame([], $this->auditRows($address->getId()));
    }

    public function testAnExpiredImportedAddressStillInItsSourceIsRevalidated(): void
    {
        $address = $this->createCourtEmailAddress($this->em, $this->court, 'registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, new \DateTimeImmutable('-200 days'), $this->user);

        $this->service->validate($address, $this->user, true);

        self::assertTrue($address->isUsableForFiling(new \DateTimeImmutable()));
        $audit = $this->auditRows($address->getId());
        self::assertSame('court_email_revalidated', end($audit)->getAction());
    }

    public function testErasePersonalIsRefusedOnAnInstitutionalAddress(): void
    {
        $address = $this->proposed('registratura@just.ro');

        $this->assertRefused('court_address.validation.error.not_allowed_state', fn () => $this->service->erasePersonal($address));
        self::assertSame(1, $this->addressCount());
    }

    private function proposed(string $email, CourtEmailPurpose $purpose = CourtEmailPurpose::REGISTRY): CourtEmailAddress
    {
        return $this->createCourtEmailAddress($this->em, $this->court, $email, $purpose);
    }

    private function assertRefused(string $key, callable $action): void
    {
        try {
            $action();
            self::fail('Expected the action to be refused with ' . $key);
        } catch (CourtEmailActionRefusedException $e) {
            self::assertSame($key, $e->messageKey);
        }
    }

    private function addressCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM court_email_address WHERE court_id = ?', [$this->court->getId()]);
    }

    /** @return list<AuditLog> */
    private function auditRows(int $addressId): array
    {
        return $this->em->getRepository(AuditLog::class)->findBy(
            ['entityType' => CourtEmailAddress::class, 'entityId' => (string) $addressId],
            ['id' => 'ASC'],
        );
    }
}
