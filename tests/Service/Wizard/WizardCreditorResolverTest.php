<?php

declare(strict_types=1);

namespace App\Tests\Service\Wizard;

use App\DTO\Wizard\Step1CreditorData;
use App\Entity\Creditor;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\CaseStatus;
use App\Enum\PersonType;
use App\Enum\RelationshipType;
use App\Service\Wizard\WizardCreditorResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WizardCreditorResolverTest extends KernelTestCase
{
    private const CUI = 'RO12345678';

    private EntityManagerInterface $em;
    private WizardCreditorResolver $resolver;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = static::getContainer()->get(WizardCreditorResolver::class);

        $this->user = new User();
        $this->user->setEmail('creditor-resolver-' . uniqid() . '@test.com');
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

    public function testACompanyWithNothingDifferentIsLinkedWithoutAsking(): void
    {
        $library = $this->libraryCreditor();
        $dto = $this->step(name: 'Alfa SRL');

        self::assertNull($this->resolver->matchLibraryCreditor($dto, $this->user, ''));
        self::assertSame($library->getId(), $dto->creditorId);
        self::assertFalse($dto->updateLibrary);
    }

    public function testADifferenceWithoutAChoiceIsPutToTheLawyer(): void
    {
        $this->libraryCreditor();
        $dto = $this->step(name: 'Beta SRL');

        $question = $this->resolver->matchLibraryCreditor($dto, $this->user, '');

        self::assertSame('Alfa SRL', $question['name'] ?? null);
        self::assertSame(['name' => ['Alfa SRL', 'Beta SRL']], $question['differs'] ?? null);
        self::assertTrue($question['canUpdate'] ?? null);
        self::assertNull($dto->creditorId);
    }

    public function testChoosingToUpdateLinksAndMarksTheUpdate(): void
    {
        $library = $this->libraryCreditor();
        $dto = $this->step(name: 'Beta SRL');

        self::assertNull($this->resolver->matchLibraryCreditor($dto, $this->user, 'update'));
        self::assertSame($library->getId(), $dto->creditorId);
        self::assertTrue($dto->updateLibrary);
        self::assertSame('Beta SRL', $dto->name, 'The step keeps its own data when it is to update the library.');
    }

    public function testUpdatingASummonedCompanyIsRefused(): void
    {
        $library = $this->libraryCreditor();
        $this->summon($library);
        $dto = $this->step(name: 'Beta SRL');

        $question = $this->resolver->matchLibraryCreditor($dto, $this->user, 'update');

        self::assertNotNull($question, 'An update choice on a summoned company does not settle the question.');
        self::assertFalse($question['canUpdate']);
        self::assertNull($dto->creditorId);
        self::assertFalse($dto->updateLibrary);
    }

    public function testASummonedCompanyIsTakenStrictlyAsTheLibraryHasIt(): void
    {
        $library = $this->libraryCreditor();
        $this->summon($library);
        $dto = $this->step(name: 'Beta SRL', iban: 'RO49AAAA1B31007593840000');

        self::assertNull($this->resolver->matchLibraryCreditor($dto, $this->user, 'library'));
        self::assertSame($library->getId(), $dto->creditorId);
        self::assertSame('Alfa SRL', $dto->name);
        self::assertNull($dto->iban, 'An empty library field is not completed from the step for a summoned company.');
    }

    public function testAnUnsummonedCompanyIsCompletedWhereTheLibraryIsEmpty(): void
    {
        $library = $this->libraryCreditor();
        $dto = $this->step(name: 'Beta SRL', iban: 'RO49AAAA1B31007593840000');

        self::assertNull($this->resolver->matchLibraryCreditor($dto, $this->user, 'library'));
        self::assertSame($library->getId(), $dto->creditorId);
        self::assertSame('Alfa SRL', $dto->name);
        self::assertSame('RO49AAAA1B31007593840000', $dto->iban);
    }

    public function testAtConfirmationATypedCreditorThatReachedTheLibraryIsLinkedOrSentBack(): void
    {
        $library = $this->libraryCreditor();

        $same = $this->step(name: 'Alfa SRL');
        self::assertFalse($this->resolver->settleCreditorWithLibrary($same, $this->user));
        self::assertSame($library->getId(), $same->creditorId);

        $differs = $this->step(name: 'Beta SRL');
        self::assertTrue($this->resolver->settleCreditorWithLibrary($differs, $this->user));
        self::assertNull($differs->creditorId);
    }

    public function testAtConfirmationALinkToAVanishedCompanyIsDropped(): void
    {
        $dto = $this->step(name: 'Alfa SRL');
        $dto->creditorId = 999999999;
        $dto->updateLibrary = true;

        self::assertFalse($this->resolver->settleCreditorWithLibrary($dto, $this->user));
        self::assertNull($dto->creditorId);
        self::assertFalse($dto->updateLibrary);
    }

    private function libraryCreditor(): Creditor
    {
        $creditor = new Creditor();
        $creditor->setUser($this->user);
        $creditor->setPersonType(PersonType::PJ);
        $creditor->setName('Alfa SRL');
        $creditor->setCui(self::CUI);
        $creditor->setAddress('Str. Alfa 1');
        $this->em->persist($creditor);
        $this->em->flush();

        return $creditor;
    }

    private function summon(Creditor $creditor): void
    {
        $debtor = new Debtor();
        $debtor->setUser($this->user);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName('Debitor SRL');
        $debtor->setAddress('Str. Debitor 1');
        $debtor->setCui('RO87654321');
        $this->em->persist($debtor);

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
    }

    private function step(string $name, ?string $iban = null): Step1CreditorData
    {
        return new Step1CreditorData(
            personType: PersonType::PJ,
            name: $name,
            cui: self::CUI,
            address: 'Str. Alfa 1',
            iban: $iban,
        );
    }
}
