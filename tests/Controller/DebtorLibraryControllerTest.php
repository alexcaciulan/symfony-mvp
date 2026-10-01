<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\DTO\Library\DebtorLibraryData;
use App\Entity\AuditLog;
use App\Entity\Debtor;
use App\Entity\LegalCase;
use App\Entity\LegalCaseDebtor;
use App\Entity\User;
use App\Enum\AnafStatus;
use App\Enum\CaseStatus;
use App\Enum\PersonType;
use App\Repository\DebtorRepository;
use App\Service\Debtor\DebtorLibraryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DebtorLibraryControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private DebtorRepository $debtors;

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->debtors = static::getContainer()->get(DebtorRepository::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        foreach ($this->userIds as $userId) {
            $conn->executeStatement('DELETE FROM audit_log WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM legal_case WHERE user_id = :id', ['id' => $userId]);
            $conn->executeStatement('DELETE FROM `user` WHERE id = :id', ['id' => $userId]);
        }

        parent::tearDown();
    }

    public function testIndexRendersTheDebtorsGrid(): void
    {
        $this->client->loginUser($this->createUser());
        $this->client->request('GET', '/debtors');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="tabulator"]');
    }

    public function testTheGridListsOnlyTheUsersLegalPersons(): void
    {
        $user = $this->createUser();
        $this->createDebtor($user, 'Alfa Mine SRL', 'RO15193236');
        $this->createDebtor($this->createUser(), 'Alta Firma SRL', 'RO14186770');
        $legacyNaturalPerson = $this->createDebtor($user, 'Ion Popescu', null);
        $legacyNaturalPerson->setPersonType(PersonType::PF);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/api/table/debtors');

        self::assertResponseIsSuccessful();
        $names = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['data'], 'name');
        self::assertSame(['Alfa Mine SRL'], $names);
    }

    public function testNewCreatesALegalPersonCompany(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/debtors/new');
        $form = $crawler->filter('form[name="debtor_library"]')->form();
        $form['debtor_library[name]'] = 'Nou Debitor SRL';
        $form['debtor_library[cui]'] = 'ro 15193236';
        $form['debtor_library[onrcNumber]'] = 'J40/1234/2020';
        $form['debtor_library[address]'] = 'Str. Nouă 5';
        $form['debtor_library[addressCounty]'] = 'Cluj';
        $this->client->submit($form);

        self::assertResponseRedirects('/debtors');
        $debtor = $this->debtors->findOneBy(['user' => $user]);
        self::assertNotNull($debtor);
        self::assertSame(PersonType::PJ, $debtor->getPersonType());
        self::assertSame('RO15193236', $debtor->getCui());
        self::assertSame('15193236', $debtor->getCuiKey());
    }

    public function testASecondCompanyWithTheSameCuiIsRefusedWhateverTheSpelling(): void
    {
        $user = $this->createUser();
        $this->createDebtor($user, 'Existent SRL', '15193236');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/debtors/new');
        $form = $crawler->filter('form[name="debtor_library"]')->form();
        $form['debtor_library[name]'] = 'Dublura SRL';
        $form['debtor_library[cui]'] = 'RO15193236';
        $form['debtor_library[onrcNumber]'] = 'J40/1234/2020';
        $form['debtor_library[address]'] = 'Str. Dublă 7';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertCount(1, $this->debtors->findBy(['user' => $user]));
    }

    public function testAnotherLawyersCompanyIsForbidden(): void
    {
        $debtor = $this->createDebtor($this->createUser(), 'Străin SRL', 'RO15193236');
        $this->client->loginUser($this->createUser());

        $this->client->request('GET', '/debtors/' . $debtor->getId() . '/edit');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testEditingASharedCompanyWarnsAndRecordsTheChangeOnEachCase(): void
    {
        $user = $this->createUser();
        $debtor = $this->createDebtor($user, 'Comun SRL', 'RO15193236');
        $case = $this->createCaseFor($user, $debtor);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/debtors/' . $debtor->getId() . '/edit');
        self::assertSelectorExists('[data-testid="debtor-shared-warning"]');
        self::assertSelectorNotExists('[data-testid="debtor-delete"]');

        $form = $crawler->filter('form[name="debtor_library"]')->form();
        $form['debtor_library[address]'] = 'Str. Mutată 9';
        $this->client->submit($form);
        self::assertResponseRedirects('/debtors');

        $entry = $this->em->getRepository(AuditLog::class)->findOneBy([
            'action' => 'debtor_identity_changed',
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
        ]);
        self::assertNotNull($entry, 'the case records that its debtor changed');
        self::assertSame(['address' => 'Str. Mutată 9'], $entry->getNewData());
    }

    public function testANewCuiClearsTheChecksTheCasesHeldForTheOldOne(): void
    {
        $user = $this->createUser();
        $debtor = $this->createDebtor($user, 'Comun SRL', 'RO15193236');
        $case = $this->createCaseFor($user, $debtor);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/debtors/' . $debtor->getId() . '/edit');
        $form = $crawler->filter('form[name="debtor_library"]')->form();
        $form['debtor_library[cui]'] = 'RO14186770';
        $this->client->submit($form);

        $this->em->clear();
        $link = $this->em->getRepository(LegalCaseDebtor::class)->findOneBy(['legalCase' => $case->getId()]);
        self::assertNull($link->getAnafStatus());
        self::assertNull($link->getInsolvencyCheckedAt());
    }

    public function testTheCuiOfASummonedCompanyCannotMove(): void
    {
        $user = $this->createUser();
        $debtor = $this->createDebtor($user, 'Somat SRL', 'RO15193236');
        $case = $this->createCaseFor($user, $debtor);
        $case->setStatus(CaseStatus::SOMATIE_TRIMISA);
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/debtors/' . $debtor->getId() . '/edit');
        $form = $crawler->filter('form[name="debtor_library"]')->form();
        $form['debtor_library[cui]'] = 'RO14186770';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->em->clear();
        self::assertSame('15193236', $this->debtors->find($debtor->getId())->getCuiKey());
    }

    public function testACompanySharingAKeyWithAnOlderRowCanStillBeEdited(): void
    {
        $user = $this->createUser();
        $this->createDebtor($user, 'Rând vechi SRL', 'RO15193236');
        $current = $this->createDebtor($user, 'Rând nou SRL', '15193236');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/debtors/' . $current->getId() . '/edit');
        $form = $crawler->filter('form[name="debtor_library"]')->form();
        $form['debtor_library[address]'] = 'Str. Corectată 3';
        $this->client->submit($form);

        self::assertResponseRedirects('/debtors');
    }

    public function testAnotherLawyerCannotDeleteTheCompany(): void
    {
        $debtor = $this->createDebtor($this->createUser(), 'Străin SRL', 'RO15193236');
        $this->client->loginUser($this->createUser());

        $this->client->request('POST', '/debtors/' . $debtor->getId() . '/delete', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotNull($this->debtors->find($debtor->getId()));
    }

    public function testAnUnusedCompanyCanBeDeleted(): void
    {
        $user = $this->createUser();
        $debtor = $this->createDebtor($user, 'Nefolosit SRL', 'RO15193236');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/debtors/' . $debtor->getId() . '/edit');
        $this->client->submit($crawler->filter('[data-testid="debtor-delete"]')->form());

        self::assertResponseRedirects('/debtors');
        self::assertNull($this->debtors->find($debtor->getId()));
    }

    public function testACompanyACaseNamesIsNotDeleted(): void
    {
        $user = $this->createUser();
        $debtor = $this->createDebtor($user, 'Folosit SRL', 'RO15193236');
        $this->createCaseFor($user, $debtor);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/debtors/' . $debtor->getId() . '/edit');
        self::assertSame(0, $crawler->filter('[data-testid="debtor-delete"]')->count(), 'the impossible action is not offered');

        $deleted = static::getContainer()->get(DebtorLibraryService::class)->delete($debtor);

        self::assertFalse($deleted);
        self::assertNotNull($this->debtors->find($debtor->getId()));
    }

    public function testCompletingFromACaseFillsOnlyEmptyFieldsAndTellsTheCases(): void
    {
        $user = $this->createUser();
        $debtor = $this->createDebtor($user, 'Comun SRL', 'RO15193236');
        $case = $this->createCaseFor($user, $debtor);

        static::getContainer()->get(DebtorLibraryService::class)->completeEmpty($debtor, new DebtorLibraryData(
            name: 'Alt Nume SRL',
            onrcNumber: 'J99/9/2099',
            administrator: 'Maria Ionescu',
        ));
        $this->em->flush();

        self::assertSame('Comun SRL', $debtor->getName(), 'a filled field is not overwritten');
        self::assertSame('J40/1/2020', $debtor->getOnrcNumber());
        self::assertSame('Maria Ionescu', $debtor->getAdministrator());
        $entry = $this->em->getRepository(AuditLog::class)->findOneBy([
            'action' => 'debtor_identity_changed',
            'entityType' => LegalCase::class,
            'entityId' => (string) $case->getId(),
        ]);
        self::assertNotNull($entry);
        self::assertSame(['administrator' => 'Maria Ionescu'], $entry->getNewData());
    }

    public function testCountyAndLocalityAreNotFilledForAnotherAddress(): void
    {
        $user = $this->createUser();
        $debtor = $this->createDebtor($user, 'Comun SRL', 'RO15193236');

        static::getContainer()->get(DebtorLibraryService::class)->completeEmpty($debtor, new DebtorLibraryData(
            address: 'Str. Alta 9',
            addressCounty: 'Cluj',
            addressLocality: 'Cluj-Napoca',
            administrator: 'Maria Ionescu',
        ));

        self::assertNull($debtor->getAddressCounty(), 'the county of another address would point to another court');
        self::assertNull($debtor->getAddressLocality());
        self::assertSame('Maria Ionescu', $debtor->getAdministrator());
    }

    public function testADeleteWithoutAValidTokenIsRefused(): void
    {
        $user = $this->createUser();
        $debtor = $this->createDebtor($user, 'Nefolosit SRL', 'RO15193236');
        $this->client->loginUser($user);

        $this->client->request('POST', '/debtors/' . $debtor->getId() . '/delete', ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNotNull($this->debtors->find($debtor->getId()));
    }

    public function testANaturalPersonFromBeforeTheLibraryIsNotOpenedForEditing(): void
    {
        $user = $this->createUser();
        $legacy = $this->createDebtor($user, 'Ion Popescu', null);
        $legacy->setPersonType(PersonType::PF);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/debtors/' . $legacy->getId() . '/edit');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setEmail('debtors-' . uniqid() . '@test.com');
        $user->setPassword('x');
        $user->setIsVerified(true);
        $user->setFirstName('Lib');
        $user->setLastName('Tester');
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = $user->getId();

        return $user;
    }

    private function createDebtor(User $user, string $name, ?string $cui): Debtor
    {
        $debtor = new Debtor();
        $debtor->setUser($user);
        $debtor->setPersonType(PersonType::PJ);
        $debtor->setName($name);
        $debtor->setCui($cui);
        $debtor->setOnrcNumber('J40/1/2020');
        $debtor->setAddress('Str. Test 1');
        $this->em->persist($debtor);
        $this->em->flush();

        return $debtor;
    }

    private function createCaseFor(User $user, Debtor $debtor): LegalCase
    {
        $case = new LegalCase();
        $case->setUser($user);
        $link = new LegalCaseDebtor($debtor);
        $link->setAnafStatus(AnafStatus::ACTIV);
        $link->setAnafCheckedAt(new \DateTimeImmutable('-1 day'));
        $link->setInsolvencyCheckedAt(new \DateTimeImmutable('-1 day'));
        $case->addDebtor($link);
        $this->em->persist($case);
        $this->em->flush();

        return $case;
    }
}
