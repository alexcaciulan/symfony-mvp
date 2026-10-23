<?php

declare(strict_types=1);

namespace App\Tests\Service\Court;

use App\Entity\CourtEmailAddress;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\CourtEmailPurpose;
use App\Enum\CourtEmailStatus;
use App\Enum\CourtFilingAddressMissingReason;
use App\Enum\CourtType;
use App\Enum\PersonType;
use App\Repository\CourtEmailAddressRepository;
use App\Service\Court\CourtFilingAddressResolver;
use App\Tests\Support\FilingCaseFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class CourtFilingAddressResolverTest extends KernelTestCase
{
    use FilingCaseFixtureTrait;

    private const NOW = '2026-10-22 12:00:00';

    private EntityManagerInterface $em;
    private CourtFilingAddressResolver $resolver;
    private User $user;
    private LegalCase $case;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = new CourtFilingAddressResolver(
            static::getContainer()->get(CourtEmailAddressRepository::class),
            new MockClock(self::NOW),
        );
        $this->filingUploadsDir = static::getContainer()->getParameter('kernel.project_dir') . '/var/uploads';
        $this->user = $this->createFilingUser($this->em, 'court-resolver');
        $this->case = $this->createFilingCase($this->em, $this->user);
    }

    protected function tearDown(): void
    {
        $this->removeFilingFixtures($this->em, $this->user ?? null, $this->case ?? null);
        parent::tearDown();
    }

    public function testJudecatorieReturnsItsConfirmedDefaultAddress(): void
    {
        $this->add('civil@just.ro', CourtEmailPurpose::SECTION_CIVIL, CourtEmailStatus::VALIDATED, '-10 days', default: true);
        $registry = $this->add('registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, '-10 days', default: true);

        $result = $this->resolver->resolve($this->case);

        self::assertSame($registry, $result->address, 'The registry comes first at a judecătorie.');
        self::assertNull($result->reason);
    }

    public function testOnlyProposedAddressesGiveNotValidated(): void
    {
        $this->add('registratura@just.ro', CourtEmailPurpose::REGISTRY);

        $this->assertMissing(CourtFilingAddressMissingReason::NOT_VALIDATED);
    }

    public function testPersonalRejectedInvalidAndOtherAddressesAreNeverReturned(): void
    {
        $this->add('ion.popescu@just.ro', CourtEmailPurpose::PERSONAL, CourtEmailStatus::VALIDATED, '-1 day', default: true);
        $this->add('respinsa@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::REJECTED);
        $this->add('invalida@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::INVALID);
        $this->add('presa@just.ro', CourtEmailPurpose::OTHER, CourtEmailStatus::VALIDATED, '-1 day', default: true);

        $this->assertMissing(CourtFilingAddressMissingReason::NO_ADDRESS);
    }

    public function testAConfirmationOlderThanOneHundredEightyDaysIsExpired(): void
    {
        $this->add('registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, '-181 days', default: true);

        $this->assertMissing(CourtFilingAddressMissingReason::VALIDATION_EXPIRED);
    }

    public function testAnAddressMissingFromItsSourceIsExpired(): void
    {
        $address = $this->add('registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, '-5 days', default: true);
        $address->setMissingSince(new \DateTimeImmutable('2026-10-20'));
        $this->em->flush();

        $this->assertMissing(CourtFilingAddressMissingReason::VALIDATION_EXPIRED);
    }

    public function testConfirmedAddressesWithoutADefaultGiveNoFilingDefault(): void
    {
        $this->add('registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, '-5 days');

        $this->assertMissing(CourtFilingAddressMissingReason::NO_FILING_DEFAULT);
    }

    public function testTribunalWithCompaniesOnBothSidesReturnsTheProfessionalsSection(): void
    {
        $this->makeTribunal(CourtType::TRIBUNAL);
        $this->registerAllPartiesAsCompanies();
        $this->add('registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, '-5 days', default: true);
        $section = $this->add('profesionisti@just.ro', CourtEmailPurpose::SECTION_PROFESSIONALS, CourtEmailStatus::VALIDATED, '-5 days', default: true);

        self::assertSame($section, $this->resolver->resolve($this->case)->address);
    }

    public function testTribunalWithALegalPersonThatIsNotACompanyHasNoAddress(): void
    {
        // An association, a foundation or a public authority: a PJ with no ONRC number.
        $this->makeTribunal(CourtType::TRIBUNAL);
        $this->registerAllPartiesAsCompanies();
        $this->addDebtor(PersonType::PJ);
        $this->add('profesionisti@just.ro', CourtEmailPurpose::SECTION_PROFESSIONALS, CourtEmailStatus::VALIDATED, '-5 days', default: true);

        $this->assertMissing(CourtFilingAddressMissingReason::TRIBUNAL_SECTION_UNKNOWN);
    }

    public function testTribunalWithACreditorWithoutAnOnrcNumberHasNoAddress(): void
    {
        $this->makeTribunal(CourtType::TRIBUNAL);
        $this->registerAllPartiesAsCompanies();
        $this->case->getCreditor()->setOnrcNumber(null);
        $this->em->flush();
        $this->add('profesionisti@just.ro', CourtEmailPurpose::SECTION_PROFESSIONALS, CourtEmailStatus::VALIDATED, '-5 days', default: true);

        $this->assertMissing(CourtFilingAddressMissingReason::TRIBUNAL_SECTION_UNKNOWN);
    }

    public function testTribunalWithANaturalPersonRegisteredAsPfaIsNotInferred(): void
    {
        $this->makeTribunal(CourtType::TRIBUNAL);
        $this->registerAllPartiesAsCompanies();
        $this->addDebtor(PersonType::PF, 'F40/123/2019');
        $this->add('profesionisti@just.ro', CourtEmailPurpose::SECTION_PROFESSIONALS, CourtEmailStatus::VALIDATED, '-5 days', default: true);

        $this->assertMissing(CourtFilingAddressMissingReason::TRIBUNAL_SECTION_UNKNOWN);
    }

    public function testTribunalWithANaturalPersonAmongTheDebtorsHasNoAddress(): void
    {
        $this->makeTribunal(CourtType::TRIBUNAL);
        $this->registerAllPartiesAsCompanies();
        $this->addDebtor(PersonType::PF);
        $this->add('registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, '-5 days', default: true);
        $this->add('profesionisti@just.ro', CourtEmailPurpose::SECTION_PROFESSIONALS, CourtEmailStatus::VALIDATED, '-5 days', default: true);

        $this->assertMissing(CourtFilingAddressMissingReason::TRIBUNAL_SECTION_UNKNOWN);
    }

    public function testTribunalNeverFallsBackOnItsRegistry(): void
    {
        $this->makeTribunal(CourtType::TRIBUNAL);
        $this->add('registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, '-5 days', default: true);

        $this->assertMissing(CourtFilingAddressMissingReason::TRIBUNAL_SECTION_UNKNOWN);
    }

    public function testCaseWithoutACourtGivesNoCourt(): void
    {
        $this->case->setCourt(null);

        $this->assertMissing(CourtFilingAddressMissingReason::NO_COURT);
    }

    public function testSpecializedTribunalBehavesLikeAJudecatorie(): void
    {
        $this->makeTribunal(CourtType::TRIBUNAL_SPECIALIZAT);
        $this->addDebtor(PersonType::PF);
        $registry = $this->add('registratura@just.ro', CourtEmailPurpose::REGISTRY, CourtEmailStatus::VALIDATED, '-5 days', default: true);

        self::assertSame($registry, $this->resolver->resolve($this->case)->address);
    }

    private function add(string $email, CourtEmailPurpose $purpose, CourtEmailStatus $status = CourtEmailStatus::PROPOSED, ?string $validatedAgo = null, bool $default = false): CourtEmailAddress
    {
        $validatedAt = $validatedAgo !== null ? (new \DateTimeImmutable(self::NOW))->modify($validatedAgo) : null;

        return $this->createCourtEmailAddress($this->em, $this->case->getCourt(), $email, $purpose, $status, $validatedAt, $this->user, $default);
    }

    private function makeTribunal(CourtType $type): void
    {
        $this->case->getCourt()->setType($type);
        $this->em->flush();
    }

    private function registerAllPartiesAsCompanies(): void
    {
        $this->case->getCreditor()->setOnrcNumber('J40/1234/2015');
        foreach ($this->case->getDebtors() as $i => $debtor) {
            $debtor->getDebtor()->setOnrcNumber('J2020' . str_pad((string) ($i + 1), 8, '0', STR_PAD_LEFT));
        }
        $this->em->flush();
    }

    private function addDebtor(PersonType $type, ?string $onrcNumber = null): void
    {
        $debtor = new Debtor();
        $debtor->setUser($this->user);
        $debtor->setPersonType($type);
        $debtor->setOnrcNumber($onrcNumber);
        $debtor->setName('Ion Debitor');
        $debtor->setAddress('Str. Debitor 3, București');
        $this->em->persist($debtor);
        $this->case->addDebtor(new LegalCaseDebtor($debtor));
        $this->em->flush();
    }

    private function assertMissing(CourtFilingAddressMissingReason $reason): void
    {
        $result = $this->resolver->resolve($this->case);
        self::assertNull($result->address);
        self::assertSame($reason, $result->reason);
    }
}
