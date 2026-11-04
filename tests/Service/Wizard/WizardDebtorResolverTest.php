<?php

declare(strict_types=1);

namespace App\Tests\Service\Wizard;

use App\DTO\Wizard\Step2DebtorEntry;
use App\DTO\Wizard\Step2DebtorsData;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\CaseStatus;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\Wizard\WizardDebtorResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WizardDebtorResolverTest extends KernelTestCase
{
    private const CUI = 'RO12345678';

    private EntityManagerInterface $em;
    private WizardDebtorResolver $resolver;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = static::getContainer()->get(WizardDebtorResolver::class);

        $this->user = new User();
        $this->user->setEmail('debtor-resolver-' . uniqid() . '@test.com');
        $this->user->setPassword('x');
        $this->user->setIsVerified(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $id = $this->user->getId();
        $conn->executeStatement('DELETE FROM legal_case_debtor WHERE legal_case_id IN (SELECT id FROM legal_case WHERE user_id = :id)', ['id' => $id]);
        $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM debtor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM creditor WHERE user_id = :id', ['id' => $id]);
        $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $id]);
        parent::tearDown();
    }

    public function testCountyAndLocalityComeFromTheStepOnlyWithTheSameAddress(): void
    {
        $library = $this->libraryDebtor();

        $sameAddress = $this->entry($library, address: 'Str. Alfa 1', county: 'Cluj', locality: 'Cluj-Napoca');
        $this->resolver->bindLibraryDebtors(new Step2DebtorsData([$sameAddress]), $this->user);
        self::assertSame('Cluj', $sameAddress->addressCounty);
        self::assertSame('Cluj-Napoca', $sameAddress->addressLocality);

        $otherAddress = $this->entry($library, address: 'Str. Beta 2', county: 'Cluj', locality: 'Cluj-Napoca');
        $this->resolver->bindLibraryDebtors(new Step2DebtorsData([$otherAddress]), $this->user);
        self::assertSame('Str. Alfa 1', $otherAddress->address, 'The library identity stands.');
        self::assertNull($otherAddress->addressCounty);
        self::assertNull($otherAddress->addressLocality);
    }

    public function testAnEmptyLibraryFieldIsCompletedFromTheStep(): void
    {
        $library = $this->libraryDebtor();
        $entry = $this->entry($library, address: 'Str. Alfa 1', email: 'office@alfa.ro');

        $this->resolver->bindLibraryDebtors(new Step2DebtorsData([$entry]), $this->user);

        self::assertSame('office@alfa.ro', $entry->email);
        self::assertSame($library->getId(), $entry->debtorId);
    }

    public function testASummonedCompanyIsNotCompletedFromTheStep(): void
    {
        $library = $this->libraryDebtor();
        $this->summon($library);
        $entry = $this->entry($library, address: 'Str. Alfa 1', email: 'office@alfa.ro');
        $entry->updateLibrary = true;

        $this->resolver->bindLibraryDebtors(new Step2DebtorsData([$entry]), $this->user);

        self::assertNull($entry->email);
        self::assertFalse($entry->updateLibrary);
        self::assertSame('Alfa SRL', $entry->name);
    }

    public function testALinkWhoseCuiNoLongerMatchesIsDropped(): void
    {
        $library = $this->libraryDebtor();
        $entry = $this->entry($library, address: 'Str. Alfa 1');
        $entry->cui = 'RO87654321';

        $this->resolver->bindLibraryDebtors(new Step2DebtorsData([$entry]), $this->user);

        self::assertNull($entry->debtorId);
        self::assertSame('RO87654321', $entry->cui, 'What the step holds is kept for a company the library does not know.');
    }

    public function testAChangedCuiWithdrawsTheChecksMadeForTheOldCompany(): void
    {
        $library = $this->libraryDebtor();
        $entry = $this->entry($library, address: 'Str. Alfa 1');
        $entry->anafStatus = AnafStatus::ACTIV;
        $entry->anafCheckedAt = new \DateTimeImmutable();
        $entry->insolvencyCheckedAt = new \DateTimeImmutable();
        $entry->autoFilled = ['name'];
        $entry->updateLibrary = true;
        $library->setCui('RO87654321');
        $this->em->flush();

        $this->resolver->refreshPickedDebtors(new Step2DebtorsData([$entry]), $this->user);

        self::assertSame('RO87654321', $entry->cui);
        self::assertNull($entry->anafStatus);
        self::assertNull($entry->anafCheckedAt);
        self::assertNull($entry->insolvencyCheckedAt);
        self::assertFalse($entry->inInsolvency);
        self::assertSame([], $entry->autoFilled);
        self::assertFalse($entry->updateLibrary);
    }

    public function testRefreshFollowsTheLibraryButKeepsWhatTheStepFilledInEmptyFields(): void
    {
        $library = $this->libraryDebtor();
        $entry = $this->entry($library, address: 'Str. Alfa 1', email: 'office@alfa.ro');
        $entry->anafStatus = AnafStatus::ACTIV;
        $library->setName('Alfa Renamed SRL');
        $this->em->flush();

        $this->resolver->refreshPickedDebtors(new Step2DebtorsData([$entry]), $this->user);

        self::assertSame('Alfa Renamed SRL', $entry->name);
        self::assertSame('office@alfa.ro', $entry->email);
        self::assertSame(AnafStatus::ACTIV, $entry->anafStatus, 'The same company keeps its checks.');
    }

    public function testATypedDebtorThatReachedTheLibraryIsLinkedOrSentBack(): void
    {
        $library = $this->libraryDebtor();

        $same = new Step2DebtorEntry(personType: PersonType::PJ, name: 'Alfa SRL', cui: self::CUI, address: 'Str. Alfa 1');
        self::assertFalse($this->resolver->debtorReachedLibrary(new Step2DebtorsData([$same]), $this->user));
        self::assertSame($library->getId(), $same->debtorId);

        $differs = new Step2DebtorEntry(personType: PersonType::PJ, name: 'Beta SRL', cui: self::CUI, address: 'Str. Alfa 1');
        self::assertTrue($this->resolver->debtorReachedLibrary(new Step2DebtorsData([$differs]), $this->user));
        self::assertNull($differs->debtorId);
    }

    public function testMatchingAsksEntryByEntryAndTakesTheChoice(): void
    {
        $library = $this->libraryDebtor();
        $differs = new Step2DebtorEntry(personType: PersonType::PJ, name: 'Beta SRL', cui: self::CUI, address: 'Str. Alfa 1');

        $pending = $this->resolver->matchLibraryDebtors(new Step2DebtorsData([$differs]), $this->user, []);
        self::assertSame(['name' => ['Alfa SRL', 'Beta SRL']], $pending[0]['differs'] ?? null);
        self::assertNull($differs->debtorId);

        $pending = $this->resolver->matchLibraryDebtors(new Step2DebtorsData([$differs]), $this->user, [0 => 'update']);
        self::assertSame([], $pending);
        self::assertSame($library->getId(), $differs->debtorId);
        self::assertTrue($differs->updateLibrary);
    }

    public function testTheLinkOfANewDebtorCarriesTheChecksOfTheEntry(): void
    {
        $checkedAt = new \DateTimeImmutable('2025-01-10');
        $entry = new Step2DebtorEntry(personType: PersonType::PJ, name: 'Gamma SRL', cui: 'RO11111111', address: 'Str. Gamma 3');
        $entry->anafStatus = AnafStatus::ACTIV;
        $entry->anafCheckedAt = $checkedAt;
        $entry->insolvencyCheckedAt = $checkedAt;

        $link = $this->resolver->buildDebtorLink($this->user, $entry, write: false);

        self::assertNull($link->getDebtor()->getId());
        self::assertSame('Gamma SRL', $link->getDebtor()->getName());
        self::assertSame(AnafStatus::ACTIV, $link->getAnafStatus());
        self::assertSame($checkedAt, $link->getAnafCheckedAt());
        self::assertSame($checkedAt, $link->getInsolvencyCheckedAt());
    }

    private function libraryDebtor(): Debtor
    {
        $debtor = new Debtor();
        $debtor->setUser($this->user);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('Alfa SRL');
        $debtor->setCui(self::CUI);
        $debtor->setAddress('Str. Alfa 1');
        $this->em->persist($debtor);
        $this->em->flush();

        return $debtor;
    }

    private function summon(Debtor $debtor): void
    {
        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('Creditor SRL');
        $creditor->setAddress('Str. Creditor 1');
        $creditor->setCui('RO87654321');
        $this->em->persist($creditor);

        $case = new LegalCase();
        $case->setUser($this->user);
        $case->setCreditor($creditor);
        $case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $case->setRelationshipType(RelationshipType::COMERCIAL);
        $case->setAmount('100.00');
        $case->setCurrency('RON');
        $case->addDebtor(new LegalCaseDebtor($debtor));
        $this->em->persist($case);
        $this->em->flush();
        // The inverse side is not kept in memory; the resolver reads the
        // company as a request would, with its case links loaded.
        $this->em->refresh($debtor);
    }

    private function entry(Debtor $library, string $address, ?string $county = null, ?string $locality = null, ?string $email = null): Step2DebtorEntry
    {
        return new Step2DebtorEntry(
            personType: PersonType::PJ,
            name: 'Alfa SRL',
            cui: self::CUI,
            address: $address,
            addressCounty: $county,
            addressLocality: $locality,
            email: $email,
            debtorId: $library->getId(),
        );
    }
}
